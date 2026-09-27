# Ice Racing (Winter) Support — Design Spec
**Date:** 2026-09-27
**Project:** WCMA Hub (221racing.com)
**Status:** Approved in conversation, waiting for review of this written spec.

---

## Overview

Summer road racing gets its class from the WCMA calculator (a declaration). Ice racing does not.
Under each club's supplementary regulations, an ice competitor submits a **tech sheet with a class
chosen from that club's class list**. No declaration is required.

Only two WCMA clubs run ice races, and their class lists differ:

- **NASCC** (Northern Alberta Sports Car Club): 2026 Ice Race Supp Regs + Street Safe Addendum
- **WSCC** (Winnipeg Sports Car Club): 2026 Ice Race Supplementary Regulations

This spec adds ice events, an ice tech sheet (in person and by photo pre-tech), ice gear checks,
and ice readiness on Home. The summer flow (calculator, declarations, summer tech, summer readiness)
does not change.

### Sub-projects

1. **Ice events and seasons** — this spec
2. **Ice tech sheet, including photo pre-tech** — this spec
3. **Readiness/Home for ice events** — this spec
4. **Ice class finder** (an optional "Help me pick" tree that fills the class dropdown) — next spec

### Decisions made while brainstorming

| Question | Decision |
|---|---|
| Winter classing | A class dropdown on the ice tech sheet. No declaration. |
| Tech sheet shape | One WCMA ice tech sheet. The class list comes from the event's club. The checklist comes from the class's **group** (drift / street-safe / caged), and a club can override item wording. |
| What car tech covers | One accepted ice sheet per **car, club and ice season** |
| Ice gear | One ice gear record per **driver and ice season**, covering both clubs. It records a **level** (street-safe or caged). |
| WSCC Ice Drift | In scope, as a `drift` group class |
| Photo pre-tech for ice | In scope for v1 |
| Where rules live | PHP data files in the repo (like `TECH_CHECKLIST_SECTIONS` / `PHOTO_REQUIREMENTS`), updated when the regs change each year |
| Ice season rollover | July. An event on or after 1 July belongs to the next year's ice season. |
| Passengers | Out of scope. They stay on site under each club's rules. |

---

## 1. Rules data: `ice-rules.php`

A new data file, versioned (`ICE_RULES_VERSION`), sourced from the 2026 regs of both clubs.

### Clubs and classes

```php
const ICE_CLUBS = [
    'NASCC' => ['label' => 'Northern Alberta Sports Car Club', 'classes' => [
        'SS'   => ['label' => 'Street Safe (FWD/RWD)', 'group' => 'street_safe', 'note' => 'Uncaged, FWD/RWD, studless DOT winter tire ≤ $160, ≤ 3,150 lb, ≤ 110" wheelbase, built after 1972'],
        'SSAWD'=> ['label' => 'Street Safe (AWD)',     'group' => 'street_safe', 'note' => 'As SS, AWD cars are classed separately'],
        'NS'   => ['label' => 'No-Stud',               'group' => 'caged',       'note' => '2WD, caged, studless DOT winter tire ≤ $160, min 165 mm'],
        'LS'   => ['label' => 'Limited Stud',          'group' => 'caged',       'note' => '2WD, caged, spec bolted tires: 9 bolts/ft, 12 mm max protrusion'],
        'CH'   => ['label' => 'Chevette',              'group' => 'caged',       'note' => 'Chevette/Acadian/Scooter/T1000 1976–87, stock 1.4/1.6, 155/80R13 bolted tires'],
        'CHSS' => ['label' => 'Chevette Street Stud',  'group' => 'caged',       'note' => 'Chevette family, street stud tire'],
        'AWD'  => ['label' => 'AWD',                   'group' => 'caged',       'note' => 'AWD, caged, ≤ 3,150 lb; tire type set with organizers in advance'],
    ]],
    'WSCC' => ['label' => 'Winnipeg Sports Car Club', 'classes' => [
        'DRIFT'   => ['label' => 'Ice Drift',                  'group' => 'drift',       'note' => 'Non-competitive lapping, any drivetrain, DOT winter or street-studded tires'],
        'FOI-SS'  => ['label' => 'Fire on Ice – Street Safe',  'group' => 'street_safe', 'note' => 'Uncaged, FWD/RWD only, studless DOT winter tire, ≤ 3,150 lb published curb weight, ≤ 110" wheelbase, built after 1972'],
        'FOI-STD' => ['label' => 'Fire on Ice – Studded',      'group' => 'caged',       'note' => 'Caged, 4-cyl ≤ 140 hp, ≥ 1,700 lb, spec bolted tires: 9 bolts/ft, 12 mm max protrusion'],
    ]],
];
```

Class codes are stored on the tech sheet's `class` column. Labels and notes are shown in the
dropdown and under it.

> The CHSS tire definition is not spelled out in the 2026 NASCC regs. Its note needs confirming
> before release.

### Groups and gear levels

| Group | Checklist set | Gear level required |
|---|---|---|
| `drift` | drift | `street_safe` |
| `street_safe` | street-safe | `street_safe` |
| `caged` | caged | `caged` (plus an FHR for NASCC LS and AWD) |

`caged` gear satisfies every group. `street_safe` gear satisfies `drift` and `street_safe`.

An FHR requirement is a per-class flag (`'fhr' => true` on NASCC `LS` and `AWD`).

### Checklists

`ICE_CHECKLIST_SECTIONS[group]` uses the same shape as `TECH_CHECKLIST_SECTIONS`: sections, each
with item keys and labels. A club overrides wording with `ICE_CLUB_OVERRIDES[club][item_key] = label`.
Overrides may only name keys that exist.

Content drawn from the regs:

- **All groups:** front and rear tow hooks (marked), headlights, brake lights, rear roof light,
  tires meet class rules, windshield and wipers, no loose items, battery secure with insulated
  terminals, no fluid leaks.
- **Street-safe adds:** stock fuel system; factory 3-point belts (no 5-point unless caged); OE seats
  with headrests or an aftermarket seat on 4 points; factory crash structures and door beams intact;
  no structural rust; windows closed; mirrors (2 side + interior); sunroof secured; exhaust exits
  behind the driver; airbags.
  - NASCC override for airbags: "Airbags removed (disabling is not enough)".
  - WSCC override for airbags: "Airbags disabled or removed".
  - Brake light count: NASCC "4 rear brake lights, 2 on or above the trunk lid"; WSCC "3 rear
    brake lights, 1 on or above the trunk lid".
- **Caged adds:** roll cage to WCMA spec with ice roof reinforcement; 5-point SFI/FIA harness in
  date; seat mounting; window net and release; kill switch (marked); hood and trunk double-latched
  or pinned; catch tanks (NASCC); mud flaps; ABS disabled (NASCC); fuel system and firewall; no
  ballast unless the class allows it and it is bolted.

### Photo requirements

`ICE_PHOTO_REQUIREMENTS` lives in `photo-requirements.php` next to `PHOTO_REQUIREMENTS`. It has its
own `ICE_PHOTO_REQUIREMENTS_VERSION` and the same entry shape, plus two fields:

- `groups`: the list of groups the shot applies to
- `club_guidance`: optional `[club => text]` wording, the same way checklist overrides work

| Shot | drift | street_safe | caged |
|---|:-:|:-:|:-:|
| Front ¾ (number, front tow hook) | ✓ | ✓ | ✓ |
| Rear ¾ (rear tow hook, exhaust exit, roof rear light) | ✓ | ✓ | ✓ |
| Driver's side and passenger's side (number, class decal) | | ✓ | ✓ |
| Tire close-up (snowflake + size + studless, or stud pattern + protrusion) | ✓ | ✓ | ✓ |
| Windshield | | ✓ | ✓ |
| Interior overall (seats, headrests, no loose items) | | ✓ | ✓ |
| Airbags | | ✓ | |
| Battery hold-down + terminals | | ✓ | ✓ |
| Brake lights | | ✓ | ✓ |
| Cage overall incl. roof reinforcement | | | ✓ |
| Seat + harness installed; harness date label | | | ✓ |
| Window net | | | ✓ |
| Kill switch + marking | | | ✓ |
| Engine bay (catch tanks) | | | ✓ |
| Mud flaps | | | ✓ |

**Ice gear shots:**

- helmet label (standard + date)
- suit or coverall label
- gloves + shoes
- FHR label, only when the class needs it

The helmet standard options cover both levels:

- **Caged:** SA2015, SA2020, SA2025 and the FIA standards
- **Street-safe:** Snell M2015, M2020 and ECE 22.05/22.06

The standard picked on the helmet photo sets the gear level: an SA/FIA standard gives `caged`,
otherwise `street_safe`. The inspector confirms the level when accepting.

> Where the clubs differ on a street-safe helmet date (NASCC "2015 Snell M", WSCC "Snell 2010M, or
> ECE 22.05 made 2015 or later"), the stricter NASCC floor applies. This keeps the gear record
> valid at both clubs.

---

## 2. Data model

### Seasons

- **Summer:** the calendar year of the event (`techSeasonFromDate`, unchanged).
- **Ice:** `iceSeasonFromDate($date)` returns the event's year, plus 1 if the month is July or
  later. So 2026-12-12 is ice season 2027, and 2027-03-07 is ice season 2027.
- Anything season-keyed now also carries a **discipline** (`summer` | `ice`). Summer 2026 and ice
  2026 never collide.

A helper, `seasonForEvent(array $event): array{discipline, season, club}`, is the single place the
key is derived.

### `events`

| Column | Type | Notes |
|---|---|---|
| `discipline` | TEXT NOT NULL DEFAULT 'summer' | `summer` \| `ice` |
| `host_club` | TEXT NULL | `NASCC` \| `WSCC`; required when `discipline='ice'`, NULL for summer |

Added with the existing `ADD COLUMN` migration helper.

### `tech_sheets`

- `submission_id` becomes **nullable**. Ice sheets have no declaration, and summer sheets still
  require one (enforced in code).
- New `discipline` column (TEXT NOT NULL DEFAULT 'summer') and new `club` column (TEXT NULL).
- `sheet_type` for ice is `'ice'`, and `class` holds the ice class code.
- `season` holds the ice season for ice sheets.
- On ice sheets, `car_id` is set directly from the chosen car. Today it is copied from the
  submission.

SQLite cannot drop NOT NULL in place. The migration therefore **rebuilds the table**:

1. create `tech_sheets_new`
2. copy the rows across with `discipline='summer'`
3. drop the old table and rename the new one
4. recreate the indexes

This runs in one transaction. It is guarded by checking `PRAGMA table_info` for the `discipline`
column, so re-running it does nothing.

### `gear_records`

- New `discipline` column (TEXT NOT NULL DEFAULT 'summer') and new `level` column (TEXT NULL:
  `street_safe` | `caged`; NULL for summer).
- UNIQUE `(driver_id, season)` becomes UNIQUE `(driver_id, discipline, season)`. This is a table
  rebuild, guarded the same way as `tech_sheets`.

### `at_track_choices`

- New `discipline` column (DEFAULT 'summer') and new `club` column (NULL).
- UNIQUE becomes `(subject_type, subject_id, discipline, club, season)`. This is a table rebuild.
  - Choosing to do car tech at the track for NASCC ice does not cover WSCC ice or summer.
  - For gear, `club` is NULL, because gear covers both clubs.

### Status keys

- `techCarKey()` becomes `car_id|discipline|club|season`. Summer rows give `car_id|summer||season`.
- Gear lookups (`db_get_gear_record_for_driver`) take a discipline.
- Summer callers pass `'summer'`, and existing behaviour is unchanged.

---

## 3. Ice tech sheet flow

### Entry

- An ice event card on Home, or a car in the Garage, links to
  `tech-sheets.php?event=ID&car=ID`.
- When the event is ice, the page does not ask for a declaration.
- It only offers the competitor's cars, minus archived ones. This is the same list as the summer
  car picker.

### Form

This is the same page and template as summer, branching on `seasonForEvent($event)['discipline']`.

- **Header fields:** unchanged (entrant, driver, make/model/colour, number, engine cc/hp, weight).
- **Class:** a `<select>` of the host club's classes (`code — label`), showing the chosen class's
  `note` under it. Required.
- **Checklist:** `ICE_CHECKLIST_SECTIONS[group]` with the club's overrides applied. It re-renders
  on the client when the class changes, because the group may change.
- **Validation:** `emptyChecklist()` and `validateChecklist()` take a `$sections` parameter that
  defaults to `TECH_CHECKLIST_SECTIONS`. The server validates the ice sheet against the sections for
  the **posted class's** group and the event's club. An unknown class code is rejected.
- **Driver equipment:** the same items as summer. The helmet line shows the standard the chosen
  class needs.
- **Single driver only.** There is no endurance variant for ice.

### Review, acceptance, output

- **Admin review (`admin-tech-review`):**
  - lists ice sheets with an "Ice · NASCC · LS" badge
  - reads `ICE_PHOTO_REQUIREMENTS` for ice sheets and `PHOTO_REQUIREMENTS` for summer sheets
- **Accepting a sheet** sets the car's tech status for `car|ice|club|season`.
- **Accepting gear** writes the driver's `ice` gear record for the season:
  - with the `level` the inspector confirms
  - the level is pre-filled from the helmet photo's standard, or from the equipment the inspector
    ticks in person
- **Email and print:** show the club name, class code and label, and "Ice {season}".

### Photo pre-tech

- The same pretech pages and flow as summer (`pretech-page.php`, `photo-upload.js`).
- The shot list comes from `ICE_PHOTO_REQUIREMENTS` filtered by the sheet's class group, with the
  club's guidance applied.
- If the class changes after photos are uploaded, shots that are no longer in the list are kept but
  hidden. Newly required shots show as missing.

---

## 4. Readiness, Home, Garage, reminders

### Readiness (`readiness-lib.php`)

For each car tagged to an **ice** event:

- **No declaration item.**
- **Car tech item:** "Ice tech for {car} at {club}", with its status from `car|ice|club|season`.
  The at-track choice is keyed the same way.
- **Gear item for each driver:** "Ice gear for {name}", from the driver's `ice` gear record for the
  season.
  - If the car has an ice sheet (so its class is known) and the gear is accepted, compare the gear
    level with the class group, and the FHR flag.
  - A shortfall is a to-do, for example: "{name}'s gear is checked for street-safe; LS needs an
    SA-rated helmet and an FHR."
  - With no sheet yet, any accepted level counts as done.

The loader's grouping (`$once` keys, `$seasons`) uses the full season key, so summer and ice items
for the same car or driver never merge.

### Home

- Ice event cards show "Ice · {club}".
- The Class / Car tech / Gear tech rows read:
  - "Class: {code} (from tech sheet)", or "Pick on tech sheet" when there's no sheet yet
  - "Car tech: Ice {season} ✓"
  - "Gear: Ice {season} ✓"

### Garage

- A car card adds an ice chip ("Ice 2027 · NASCC · LS") next to the summer chip.
- The chip only appears when the car has ice sheets or ice events.

### Reminders

Reminders are built from readiness items, so they pick up ice items with no separate change. Check
that the copy reads correctly with the ice labels.

---

## 5. Admin: ice events (`admin.php`)

- **Event form:**
  - Discipline radio (Summer / Ice, default Summer).
  - Choosing Ice reveals a required Host club select (NASCC / WSCC). The server rejects an ice
    event with no club.
- **Event list:** an "Ice · NASCC" badge.
- **Existing events** migrate as summer, with no host club.

---

## 6. Testing (PHPUnit, `wcma-calculator/tests/`)

- **Seasons:** `iceSeasonFromDate` for Dec, Jan, Mar, Jun and Jul dates; `seasonForEvent` for
  summer and ice events.
- **Rules data integrity:**
  - every class has a known group
  - every group has checklist and photo entries
  - club overrides and `club_guidance` only name keys that exist
  - class codes are unique within a club
- **Checklist validation:** ice sheets pass or fail against their group's sections; an unknown
  class is rejected; the summer validation regression still passes.
- **DB:**
  - an ice sheet can be inserted with no submission
  - status keying keeps summer and ice apart, and NASCC and WSCC apart
  - gear uniqueness is per discipline
  - at-track choices are keyed by discipline and club
- **Migrations:** start from a summer-only database fixture and run `db_init`. Check that:
  - the row counts are unchanged
  - every row is `discipline='summer'`
  - summer statuses are identical before and after
  - a second `db_init` does nothing
- **Readiness:**
  - an ice event has no declaration item
  - car tech is per club
  - a gear level shortfall produces a to-do
  - an FHR shortfall for NASCC LS produces a to-do
  - summer readiness is unchanged
- **Admin:** creating an ice event requires a club.

---

## Out of scope

- **Ice class finder:** sub-project 4, in the next spec.
- **Passengers:** the NASCC passenger sticker and WSCC passenger rules.
- **Registration and entry for Ice Drift:** that happens through MSR.
- **Admin editing of class lists or checklists:** regs changes are made in code.
- **Class-specific engine rules** (e.g. Chevette engine internals): these are checked by the
  scrutineer on site. The checklist has a single "meets class rules" item.
