# Race Tech Sheets — Design Spec
**Date:** 2026-09-22
**Project:** WCMA Classing Calculator (221racing.com)
**Status:** Approved for implementation

---

## Overview

Today, competitors class their car with the calculator, save it to My Cars, then separately fill out a paper Vehicle Technical Inspection Sheet at the track (standard, or a supplemental Endurance sheet listing additional drivers' safety gear). This feature lets a competitor generate that tech sheet online from an already-classed car, submit it (which emails a copy to themselves and to `classing@wcma.ca` for awareness, replicating the paper form's layout), and have a club tech inspector mark it **Reviewed** — with per-item sign-off on driver safety equipment — from the admin side, including from a phone at the track.

Reference forms (reviewed during design, not stored in-repo):
- Standard: 2021 Race Tech Form — header (entrant/car/driver identity), a ~40-item self-verified vehicle checklist (Under Vehicle, Wheels & Tires, Engine Compartment, Vehicle Interior, Vehicle Exterior, Fuel Tank Compartment), a 9-item Driver Safety Equipment table (competitor-confirmed + Tech Rep Approved columns), and signature/declaration lines.
- Endurance: 2021 Endurance Tech Form — repeats only the 9-item Driver Safety Equipment table per additional driver (2 through 7); no vehicle checklist repetition.

Out of scope for this iteration: reusable driver-gear profiles (each sheet is filled fresh, per prior decision), tech sheets generated from unclassed drafts (submissions only), and a public race-calendar integration (events are entered manually by admins).

---

## Data Model

### New `events` table

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    event_date  DATE NOT NULL,
    location    TEXT,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL
);
```

Admin-managed only. Competitors choose from `active = 1` events when submitting a tech sheet. Deactivating (not deleting) an event hides it from the picker without breaking existing sheets' foreign key.

### New `tech_sheets` table

```sql
CREATE TABLE tech_sheets (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id           INTEGER NOT NULL,   -- FK submissions.id, source classed car
    user_id                 INTEGER NOT NULL,   -- FK users.id, owner/entrant account
    event_id                INTEGER NOT NULL,   -- FK events.id
    sheet_type              TEXT NOT NULL,      -- 'standard' | 'endurance'

    -- Header, copied from the submission at generation time (frozen, not live-read,
    -- since class/weight could be re-classed later and the sheet must reflect what
    -- was true when submitted)
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

    -- The ~40 "competitor verifies OK" checklist items, flat {item_key: 'ok'|'na'}.
    -- No per-item tech-rep interaction on this section (matches the paper form).
    checklist_json          TEXT NOT NULL,

    -- 9 driver safety equipment items for the entrant/driver, each:
    -- {competitor_confirmed: bool, value: string|null (rating, e.g. helmet/suit spec),
    --  tech_approved: bool|null}
    driver1_equipment_json  TEXT NOT NULL,

    log_book_turned_in      INTEGER,            -- 0/1, nullable until competitor answers

    entrant_signature_path  TEXT,
    entrant_signed_at       DATETIME,
    driver_signature_path   TEXT,
    driver_signed_at        DATETIME,
    tech_signature_path     TEXT,               -- set when reviewed
    tech_signed_at          DATETIME,

    status                  TEXT NOT NULL DEFAULT 'submitted', -- 'submitted' | 'teched'
    reviewed_by_user_id     INTEGER,            -- FK users.id, the admin who reviewed
    reviewed_at             DATETIME,

    email_sent              INTEGER DEFAULT 0,
    email_send_count        INTEGER NOT NULL DEFAULT 0,
    last_emailed_at         DATETIME,

    created_at              DATETIME NOT NULL,
    updated_at              DATETIME NOT NULL
);
```

`status` is the whole story on the review side — there is no pass/fail. "Teched" means the inspector has gone through the Driver Safety Equipment items and signed off; the vehicle checklist itself stays competitor-attested only, exactly as on the paper form.

### New `tech_sheet_drivers` table

```sql
CREATE TABLE tech_sheet_drivers (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    tech_sheet_id    INTEGER NOT NULL,   -- FK tech_sheets.id
    driver_number    INTEGER NOT NULL,   -- 2 through 7
    driver_name      TEXT NOT NULL,
    equipment_json   TEXT NOT NULL       -- same shape as driver1_equipment_json
);
```

Only populated for `sheet_type = 'endurance'`. No signature column here — per your decision, the entrant/driver signature on the main sheet covers accountability for the whole team's gear.

### Why JSON blobs for checklist/equipment

Matches the existing `drafts.form_data` precedent. The checklist is copied from a fixed paper form that changes rarely, and there's no reporting need this generation to query individual items (e.g. "how many cars failed on brake hoses") — a flat JSON blob keeps the schema small and the admin edit UI simpler to build (one accordion component reused for both competitor fill-in and admin edit/review), versus 40+ dedicated columns.

---

## Mandatory Fields

- Event, sheet type
- Header: car number, car colour, engine CC/HP, car weight, entrant name, driver name (all pre-filled from the submission, but must be non-blank to submit)
- Every one of the ~40 checklist items — must be explicitly marked OK or N/A, never left blank
- Driver safety equipment: Helmet rating and Suit rating are required text fields; Goggles/visor, Shoes, Socks, Gloves, Balaclava, Head & Neck Restraints are required confirm-toggles. **Underwear** is optional, matching the paper form's "(if required)" annotation.
- Log book turned in (Yes/No)
- Entrant signature and driver signature (captured, not typed)
- Endurance: driver 1 is always required; drivers 2–7 are optional/add-as-needed, but any added driver's equipment fields follow the same mandatory rules as driver 1.

---

## Competitor Flow

1. On the existing **My Cars** list, each **submission** row (not drafts — only classed cars have the weight/HP/class data a tech sheet needs) gets a **"Submit Tech Sheet"** action.
2. Clicking it opens a new page to pick an **event** (dropdown of `active` events) and **sheet type** (Standard / Endurance). Endurance shows an "Add driver" control for drivers 2–7.
3. The tech sheet form opens pre-filled from the submission (make/model/class/weight/HP/entrant name); the competitor fills in the rest.
4. **Checklist UX** (the ~40 items): an accordion, one section per checklist group (Under Vehicle, Wheels & Tires, Engine Compartment, Vehicle Interior, Vehicle Exterior, Fuel Tank Compartment), each with:
   - A sticky progress bar at the top of the page ("25 of 40 items checked / 62%")
   - A "Mark all OK" quick action per section for the common case
   - Each item as a row with two tap targets: **OK** / **N/A** (large touch targets, mobile-first)
   - Per-section complete counts shown on the collapsed header, so a competitor can see at a glance which sections still need attention
   This pattern (validated via mockups against two alternatives — a flat toggle list and a one-item-per-screen wizard) was chosen for balancing speed for returning competitors (bulk "mark all OK") against making incomplete sections visually obvious.
5. Submitting requires the entrant and driver signatures, captured via an HTML canvas signature pad (pointer-events based, no external library needed) and saved as PNG files under `uploads/tech-sheets/{id}/`, matching the existing `car_image_path`-style upload pattern.
6. On save: `status = 'submitted'`. An HTML email is sent to the competitor and to `classing@wcma.ca`, rendering the full tech sheet in a layout that replicates the original paper form (header grid, checklist tables, driver equipment table, signature images) — not a compact summary. The same rendering is reused for the online view page and a print-friendly view (browser print, same pattern as the existing submission detail print view).
7. A **"My Tech Sheets"** list (new tab on the account page, alongside My Cars) shows the competitor's sheets with event and status (`submitted` / `teched`). Sheets can be edited and re-submitted (re-signing, re-emailing) any time before `status = 'teched'`; once teched, the sheet locks for the competitor and only an admin can edit it.

---

## Admin & Tech-Inspector Flow

No new role — tech inspectors use the existing admin role, gated the same way as current admin pages.

1. **New "Events" admin page**: CRUD list (name, date, location, active toggle) using the same table/search/pagination shell as the existing admin submissions/users lists.
2. **New "Tech Sheets" admin list**: filterable by event and status, same list shell pattern. Desktop-oriented, like the rest of the admin area — this browsing/filtering work happens at a desk.
3. **Detail/edit view**: full read/edit access to every field on a sheet, mirroring the existing admin submission detail-view editing already built, including the checklist accordion (same component as the competitor flow) and driver equipment fields.
4. **Review action** — this is the one screen that must be mobile-first, since inspectors use it from a phone trackside: the same accordion component as the competitor flow, but scoped to Driver Safety Equipment only, showing the competitor's confirmed value/rating alongside a **Tech Approved** toggle per item, for the driver and (for endurance) each additional driver 2–7. Saving here:
   - Sets `status = 'teched'`, `reviewed_by_user_id`, `reviewed_at`
   - Captures the tech rep's signature via the same canvas pad, stored to `tech_signature_path`
   - Locks the sheet from further competitor edits
5. On review: a second notification email goes to the competitor (and `classing@wcma.ca`) confirming the sheet has been reviewed, using the same full-fidelity HTML rendering.
6. **Resend**: both competitor and admin sides get a resend action for either the submission-confirmation or the reviewed-notification email, mirroring the existing `account.php?action=resend` / admin resend pattern.

---

## Email & Rendering

One shared rendering function produces the tech sheet as HTML styled to visually replicate the paper form's layout (two-column header grid, bordered checklist tables per section, driver equipment table with rating/confirmed/approved columns, signature images inline, declaration text). This single rendering is reused for:
- The submission-confirmation email body
- The reviewed-notification email body
- The online "view sheet" page (competitor and admin)
- The print view (browser print via existing print-view CSS pattern)

This avoids building and maintaining separate summary-email and full-detail-view templates.

---

## Summary of New Surfaces

| Surface | Who | Notes |
|---|---|---|
| Submit Tech Sheet (event/type picker + form) | Competitor | From My Cars, per submission |
| My Tech Sheets list | Competitor | New account.php tab |
| View / print tech sheet | Competitor, Admin | Shared rendering |
| Events admin (CRUD) | Admin | Desktop-oriented |
| Tech Sheets admin list | Admin | Desktop-oriented |
| Tech Sheet detail/edit | Admin | Desktop-oriented |
| Review (mark Reviewed) | Admin (tech inspector) | **Mobile-first**, reused accordion component |
