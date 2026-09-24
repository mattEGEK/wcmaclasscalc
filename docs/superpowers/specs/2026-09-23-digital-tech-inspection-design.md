# Digital Tech Inspection (Pre-Tech) — Design Spec
**Date:** 2026-09-23
**Project:** WCMA Classing Calculator (221racing.com)
**Status:** Draft for review

---

## Overview

Tech inspection is annual per car and annual per driver's gear, not per race. Today the tech sheet (built 2026-09-22, see `2026-09-22-tech-sheets-design.md`) is a per-event competitor attestation, and an inspector reviews it in person at the track.

This feature lets a competitor optionally **pre-tech**: submit a set of photos of the car, and of each driver's gear, ahead of time. An inspector reviews the photos remotely and marks them **accepted**. A pre-teched competitor skips inspection at the track and only collects their decals. A competitor who submits no photos is teched in person, exactly as today.

Two properties shape the whole design:

1. **The annual is earned once, by either path.** Whichever review first succeeds for a car in a calendar year, remote (photos) or in person, is that car's annual. Later sheets need no review and simply show "annual accepted". Gear works the same way per driver.
2. **The per-event sheet stays a competitor attestation.** Each event the competitor still submits a sheet confirming the car is as declared and safe to race. The inspector only needs to know it was received.

### Terminology (binding)

The club and its inspectors confirm that what the competitor submitted matches the reality of the car. They do not certify that a car or gear is safe. All UI and email copy uses **reviewed**, **accepted** and **pre-teched**, never *approved*, *passed* or *safe*. Every acceptance email carries: *"Acceptance confirms that what you submitted matches what was reviewed. It is not a certification that the vehicle or equipment is safe."* The existing labels "Tech Approved" and "Tech Rep Approved" are renamed as part of phase 1.

### Out of scope

- Expiry reminders and notifications (data is captured so this can follow later).
- Sticker or decal number tracking. Status only: the roster shows who needs decals.
- Claiming or transferring a gear record from a team captain to a driver's own account.
- Multiple photos per requirement, and an admin UI for editing the photo list (it is a versioned file).
- Ice racing and vintage-specific rules (the list follows the 2026 touring-car regulations).

---

## Concepts

| Concept | Meaning |
|---|---|
| **Car identity** | Owner (user) + normalised car number + calendar year. Normalisation: trim, uppercase, strip leading zeros (unless all zeros). No new car table. |
| **Season** | Calendar year of the event's date (sheets) or of creation (gear records). |
| **Pre-tech photo set** | The photos and typed values attached to a sheet (car) or a gear record (driver). Optional. |
| **Accepted** | An inspector has reviewed the car (or gear) for the year, via `photos` or `in_person`. |
| **Gear record** | One per driver per year: driver name, optional licence number, photos, status. Created and managed by whoever adds the driver. |

### Derived car status

For a car identity, computed from all of that owner's sheets with that number in that year, in precedence order:

1. `accepted` — any sheet is accepted (shows *Pre-teched* if via photos, *Teched* if in person)
2. `needs_changes` — a photo set was sent back and has not been resubmitted
3. `pending_review` — a photo set is submitted and awaiting review
4. `photos_draft` — photos started but not submitted for pre-tech
5. `none` — *Needs tech at the track*

Gear status has the same five states, read from the gear record directly. The derivation is a pure function so it is unit-testable independent of the database and rendering.

### Not blocking

An event sheet is always submittable regardless of car or gear status. Chips show the state. Inspectors decide at the track. Photos never block a sheet; only the *pre-tech submit* action requires a complete set.

---

## Photo Requirements

Defined in `photo-requirements.php`, a versioned data file (`PHOTO_REQUIREMENTS_VERSION`), mirroring how the tech checklist lives in `tech-sheet-data.php`. Each entry has: `key`, `scope` (`car` | `gear`), `tier`, `label`, `guidance`, optional `typed` field (`{type, label, options?}`), and `reg` (regulation reference). Tiers:

- **required** — must be present to submit for pre-tech
- **conditional** — required only if the competitor toggles "this applies to my car"
- **recommended** — prompted, never blocks; the inspector sees when it is missing

Because the list is a data file, the club can change a tier (for example, enforce a recommended photo) or add a photo when the rules change (a fire suppression system becomes mandatory on 2027-05-01) without touching logic. A photo records the requirement version it was taken under. Changing the version never invalidates an already-accepted set.

### Car (15 required)

| Key | Photo | Typed value | Reg |
|---|---|---|---|
| `front_34` | Front 3/4: car number and front tow point visible | | App. 3.D, 2.U |
| `rear_34` | Rear 3/4: exhaust exit and rear tow point visible | | App. 2.S, 2.U |
| `side_driver` | Driver's side: number, class, minimum weight, decals | | App. 3 |
| `side_passenger` | Passenger side | | App. 3 |
| `cage_overall` | Roll cage, overall | | App. 1 |
| `cage_mounts` | Main hoop and mounting points | | App. 1 |
| `cage_padding` | Padding at helmet-contact areas | | App. 2.H.3 |
| `seat_harness` | Seat and harness, installed | | App. 2.A |
| `harness_date` | Harness date stamp, close-up | date (MM/YYYY) | App. 2.A.1.c |
| `window_net` | Window net | | App. 2.E |
| `fire_system` | Fire suppression (bottle, gauge, date) | date | App. 2.B |
| `kill_switch` | Kill switch and its marking | | App. 2.C |
| `battery` | Battery: hold-down and terminals | | App. 2.T |
| `engine_bay` | Engine bay: catch tanks, firewall | | App. 2.F, 2.K, 2.M |
| `interior` | Interior, overall | | App. 2.H |

### Car (6 conditional)

`seat_label` (plastic or composite seat), `fuel_cell` (installation and FIA FT3 / SFI 28.3 label), `windshield_clips` (polycarbonate windshield retaining clips), `scattershield` (scattershield and driveshaft hoops, tube-frame cars), `ballast` (ballast mounting), `aero` (splitter or wing).

### Gear, per driver

| Key | Photo | Tier | Typed value | Reg |
|---|---|---|---|---|
| `helmet_label` | Helmet certification label | required | standard (select: SA2020, SA2025, FIA 8860-2010, 8859-2015, 8860-2018) and date | App. 4.B |
| `suit_label` | Race suit label | required | rating (SFI 3.2A/x, FIA) | App. 4.D |
| `fhr_label` | Frontal head restraint label | required | standard (FIA 8858-2002/2010, SFI 38.1) and date | App. 4.C |
| `gear_flatlay` | Gloves, shoes, socks and balaclava | required | | App. 4.E |
| `helmet_back` | Helmet back label: name, date of birth, allergies | **recommended** | | App. 4.B.5 |
| `underwear_label` | Fire-resistant underwear label | conditional (suits that require it) | | App. 4.D |

The regulations require the helmet back label (App. 4.B.5), but it is not currently enforced, so it is *recommended*. The requirement file records this with a comment so it can be flipped to required if enforcement starts. The regulations use the term *Frontal Head Restraint*; UI copy follows.

---

## Data Model

### `tech_sheets` (extend)

- `photo_status` TEXT NULL: `NULL` (no photos) | `draft` | `submitted` | `needs_changes` | `accepted`
- `accepted_via` TEXT NULL: `photos` | `in_person`
- `car_number_norm` TEXT: normalised car number, indexed with `user_id` for the identity lookup
- `season` INTEGER: calendar year of the event date
- Existing `status` (`submitted` | `teched`) keeps its meaning: `teched` = accepted. Acceptance sets `status = 'teched'`, `accepted_via`, `reviewed_by_user_id`, `reviewed_at`. The existing lock rule (a teched sheet locks for the competitor) is unchanged.
- `driver1_gear_record_id` INTEGER NULL

### `tech_sheet_drivers` (extend)

- `gear_record_id` INTEGER NULL

### `gear_records` (new)

```sql
CREATE TABLE gear_records (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id       INTEGER NOT NULL,   -- who created and manages it
    driver_name         TEXT NOT NULL,
    licence_no          TEXT,               -- optional, helps spot duplicates
    season              INTEGER NOT NULL,
    photo_status        TEXT,               -- NULL | draft | submitted | needs_changes | accepted
    status              TEXT NOT NULL DEFAULT 'open',  -- open | accepted
    accepted_via        TEXT,               -- photos | in_person
    reviewed_by_user_id INTEGER,
    reviewed_at         DATETIME,
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NOT NULL
);
```

A gear record is a person, not an account. A team captain can create records for co-drivers by name. A driver with their own account can create their own. The next year's record is created on first use with the previous name and licence number pre-filled.

### `inspection_photos` (new)

```sql
CREATE TABLE inspection_photos (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_type         TEXT NOT NULL,      -- 'tech_sheet' | 'gear_record'
    subject_id           INTEGER NOT NULL,
    requirement_key      TEXT NOT NULL,
    requirement_version  INTEGER NOT NULL,
    file_path            TEXT NOT NULL,
    typed_value          TEXT,
    review_status        TEXT NOT NULL DEFAULT 'pending',  -- pending | accepted | retake
    reviewer_note        TEXT,
    applies              INTEGER NOT NULL DEFAULT 1,       -- conditional toggle
    created_at           DATETIME NOT NULL,
    updated_at           DATETIME NOT NULL,
    UNIQUE (subject_type, subject_id, requirement_key)
);
```

One photo per requirement; a retake replaces the file. Conditional toggles that are switched off are stored as `applies = 0` rows without a file, so the set's completeness is reproducible.

### Event sheet gear section

The competitor-attested equipment confirmation on the event sheet (helmet and suit rating text, confirm-toggles) stays, because each event the competitor re-attests. The per-item **Tech Approved** column is removed. Acceptance of gear now lives on the gear record. Per-item inspector sign-off is not retained; the gear record is accepted as a whole, with the attested items shown for the inspector's reference.

---

## Photo Handling

- **Capture:** `<input type="file" accept="image/*" capture="environment">` opens the phone camera directly.
- **Resize before upload:** the browser scales each photo to about 1600px on the long edge and re-encodes as JPEG (~300-600 KB), which also strips EXIF/GPS data. Phone originals of 3-10 MB never reach the server, well under the current 2 MB upload cap.
- **One photo per request:** each photo uploads as soon as it is chosen (`inspection.php`, JSON), so a weak connection at a shop or paddock loses at most one photo, and the set is a resumable draft.
- **Server validation:** MIME sniffed with `getimagesize`, JPEG/PNG/WebP only, size cap, dimension cap. The requirement key must exist in the current requirements version.
- **Storage:** `uploads/inspection/{subject_type}/{subject_id}/{requirement_key}.jpg`. `uploads/` is already Deny-from-all.
- **Serving:** `inspection-photo.php?id=`, authorising the owner and admins only. Gear photos are personal, so they are never public and never in email bodies.

---

## Competitor Flow

1. **Sheet form**: a new section, **"Get pre-teched (optional)"**, with a progress bar across the required photos (conditional toggles add to the count), typed-value fields beside the relevant photos, and a note that pre-tech means collecting decals without inspection at the track. It saves as a draft. **Submit for pre-tech** enables only when every required photo (plus applicable conditional ones) is present. Recommended photos show a soft prompt.
2. **Gear on the sheet**: the entrant and each additional driver are picked from *My Drivers* (or added inline). Each shows a gear chip and its own optional pre-tech photo set, filled in the same section pattern.
3. **My Drivers** (new list on the account page): the competitor's gear records for the year, with status chips, edit, and "start next year" pre-fill.
4. **My Tech Sheets** and **My Cars**: chips for car status (*Pre-teched*, *Teched*, *Pending review*, *Needs changes*, *Needs tech at the track*) and per-driver gear status.
5. **Sent back**: the competitor sees which photos need a retake and the inspector's note. Retaking a photo and resubmitting returns the set to `submitted`.
6. Event sheets are always submittable; status chips are informational.

## Inspector Flow

1. **Pre-tech review queue** (admin): sets awaiting review, filterable by event, car and gear, oldest first.
2. **Review page (mobile-first)**: a photo gallery, each photo shown with its requirement label, guidance, and typed value. The inspector marks any photo **Retake** with a note, or accepts. **Accept** sets `photo_status = accepted`, `accepted_via = photos`, and `status = teched`. **Send back** requires at least one retake note and sets `needs_changes`.
3. **Event roster**: per event, every entrant with car and gear chips. The **Needs tech at the track** filter lists who still needs in-person inspection. Pre-teched entrants only collect decals.
4. **In-person acceptance**: the existing review action (mark a sheet reviewed from a phone) is kept, now also setting `accepted_via = in_person`. A matching action exists for gear records.

---

## Emails

Same branded layout as the submission and tech sheet emails (`email-helpers.php` logo). Recipients: the competitor and the configured tech-sheet address.

| Email | To | Content |
|---|---|---|
| Pre-tech submitted | competitor, club | Confirmation with photo count; photos are not embedded |
| Sent back | competitor | The photos to retake with notes, link to fix |
| Accepted | competitor, club | "Pre-teched — collect your decals at the event" plus the disclaimer above |

---

## Impact on Existing Code

- `tech-sheet-render.php`: rename "Tech Rep Approved" and the "Tech Approved" toggle wording; drop the per-item approval column; add status chips.
- `tech-sheet-data.php`: `TECH_DRIVER_EQUIPMENT_ITEMS` loses tech-approval fields; photo requirements live in the new file.
- `db.php`: migrations for the extended columns and two new tables, in the same idempotent `PRAGMA table_info` style as existing migrations.
- `admin.php` and `account.php`: new lists and review pages, reusing the existing list shell and the `renderCommonNav` pattern.

---

## Phasing

1. **Foundations:** the requirements file, photo upload/storage/serving with browser resize, and the wording rename.
2. **Car pre-tech:** the photo section on the sheet form, derived car status, the review queue and page, sent-back and accepted emails, and the event roster with the *Needs tech at the track* filter.
3. **Gear records:** *My Drivers*, gear pre-tech photos, gear review, and linking records into sheets and the roster.
4. **Later:** expiry surfacing and reminders from the typed dates.

## Testing

- PHPUnit: requirement file integrity (unique keys, valid tiers, typed field types); the car and gear status derivation across all precedence cases; car-number normalisation; photo upload validation; set-completeness including conditional toggles; email rendering.
- Browser (Playwright, existing scratch tooling): resize-before-upload, draft resume after reload, and the mobile review page.
- Manual: a real phone photo through the whole flow on the deployed host, since IONOS upload and PHP limits are environment-specific.

## Open Items

- **Per-item gear sign-off:** dropped for simplicity. Reinstate on the gear record if inspectors want item-level sign-off when teching gear in person.
- **Photos per requirement:** one photo each for now. Revisit if inspectors ask for wide plus close-up on the same item.
- **Car renumbering mid-season** starts a new car identity and a fresh annual. Accepted as reasonable, since the car has changed.
