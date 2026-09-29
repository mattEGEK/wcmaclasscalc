# TA/Drift Tech and Gear — Design Spec

Date: 2026-09-29. Status: approved in brainstorming, awaiting written-spec review.

## Overview

Drivers can run one or more **formats** at a summer event: Race, Time Attack (TA) and Drift. A car
can run all three in one weekend. Tech gets stricter from TA/Drift up to Race, so an entry needs
only the strictest tech its formats call for: a car that races does the race tech sheet it does
today, and that also covers TA and Drift. An entry that is **only** TA and/or Drift needs the
new, lighter **TA/Drift** tier:

- a car created without a WCMA class declaration,
- a **TA/Drift tech sheet** for each event. One accepted sheet approves the car for that host
  club for the calendar year. After that, sheets for later events are **encouraged but not
  required**.
- **TA/Drift gear**, accepted once per driver per calendar year,
- a per-event tick box: "I have read the {club} supplementary regulations and my car complies".
  Submitting that event's sheet also counts as ticking it.

TA and Drift rules belong to each club (supplementary regulations), not to WCMA. The hub doesn't
class TA/Drift cars or check them against a club's rules. It carries one shared checklist, built
from the requirements the clubs' regulations have in common.

### Sources (read 2026-09-29)

- WCMA: Technical Regulations 2026; Canadian National SoloSport Regulations – Time Attack (2020,
  linked from wcma.ca/solosport/time-attack); WCMA SoloSport Vehicle Technical Self-Declaration.
- NASCC: 2025 Time Attack Regulations v6 (Street / Street+ / Race classes; season tech sticker).
  The 2026 NASCC documents are Google Docs we could not open.
- WSCC: 2024 Time Attack Supplementary Regulations v4.0; 2026 Ice Race Supplementary Regulations
  (Ice Drift, §3).
- No club publishes summer drift rules. Neither club runs time attack on ice.

### Decisions made while brainstorming

| Question | Decision |
|---|---|
| Where the format lives | On the entry (driver's car at an event), not the event. An event has no format. |
| Tech needed for an entry | The strictest of its formats: Race needs race tech; TA and Drift only need TA/Drift. |
| Winter | Out of scope. WSCC Ice Drift is already an ice class with its own light checklist; leave it there. |
| How long TA/Drift approval lasts | The calendar year, per car **per host club**. Race approval (all clubs) also covers it. |
| Substantial change | The inspector revokes the acceptance with a required note ("car changed"); the next entry asks for a new sheet. |
| Per-event paperwork | A TA/Drift sheet for each event is encouraged. It's required only until the car is approved for that club and year. Each TA/Drift-only entry needs the supplementary-regulations tick box (or that event's sheet). |
| TA/Drift gear | Per driver per calendar year, a lower rung on the summer gear record. Race gear covers it. |
| Photo pre-tech | Yes, with short photo lists for car and gear. |
| Tow points | Required (factory ones are fine). |
| Fire extinguisher | Recommended. |
| MotorsportReg import | Also keeps stand-alone Time Attack and Drift events. |
| Naming | People see "TA/Drift". Code uses `ta_drift`. |
| Build approach | Extend the existing tech sheet and gear records (a new sheet type and gear level), not new tables and not a third event discipline. |

### Sub-projects (one plan each)

1. **Foundations**: rules data, schema, entry formats, the status keys and the approval ladder.
2. **TA/Drift sheet and gear**: the form, review, photo pre-tech, gear level on accept, revoke note.
3. **Readiness and screens**: Home, Garage, reminders, Inspect chips, and the MotorsportReg types.

## 1. Rules data: `ta-drift-rules.php`

A pure data file in the style of `ice-rules.php`, with `TA_DRIFT_RULES_VERSION = 1` and a header
naming the sources above.

### Checklist (`TA_DRIFT_CHECKLIST_SECTIONS`)

Items are marked ok or n/a, the same as the race checklist. Items marked *(caged)* show only when
the sheet says the car has a roll bar or cage. "Recommended" items can't block acceptance.

1. **Brakes**: working on all four wheels, no leaks.
2. **Wheels and tires**: all lug nuts present and tight; hubcaps and trim rings removed; DOT
   tires in good condition.
3. **Battery**: securely held down (no bungee cords); positive terminal insulated.
4. **Fluids**: no leaks; coolant overflow and crankcase breather go to catch cans.
5. **Cabin**: nothing loose in the cabin or trunk; nothing hanging from the mirror.
6. **Seats**: securely mounted; an aftermarket seat at a minimum of four points.
7. **Belts**: in good condition; factory 3-point belts OK; a harness only if a roll bar or cage
   is fitted.
8. **Windows and roof**: windows up unless window nets are fitted; sunroof or convertible top
   closed and locked.
9. **Mirrors and lights**: at least one rear-view mirror; brake lights working.
10. **Tow points**: front and rear, factory ones OK. Required.
11. **Fire extinguisher**: within the driver's reach, on a quick-release mount. Recommended.
12. **Numbers**: car number on both sides.
13. *(caged)* **Roll bar or cage** built to WCMA spec; 5- or 6-point harness fitted.
14. **Supplementary regulations**: "I have read the {host club} supplementary regulations and my
    car complies."

### Gear (`TA_DRIFT_EQUIPMENT_ITEMS`)

These use the same item format as `TECH_DRIVER_EQUIPMENT_ITEMS` (the driver confirms each and may
add a value).

- Helmet: Snell SA or M 2015 or newer, or ECE 22.05 made within the last 10 years. It must be
  Snell SA if the car is caged. The rating is required.
- Long pants, closed-toe shoes, and a sleeved shirt made of natural fibre.
- *(caged)* SFI 38.1 or FIA 8858 head and neck restraint.

A suit and gloves aren't on the list. Cars that need them (modified, non-DOT tires, NASCC "Race"
class) run the Race format and do race tech.

### Photo requirements

These are added to `photo-requirements.php` with a `tad_` key prefix and chosen by
`photoRequirementsFor()`:

- **Car**: front three-quarter view, rear three-quarter view, interior from the driver's door,
  battery hold-down, front tow point, rear tow point, and *(caged)* the cage.
- **Gear**: the helmet label (with the standard and date), and *(caged)* the head and neck
  restraint label.

## 2. Data model

No new tables. All migrations go through the existing `db_add_column_if_missing` in `db_init()`.

### `event_plans` (entries)

- `formats TEXT NOT NULL DEFAULT 'race'`: a comma list from `race`, `ta`, `drift`. Existing rows
  are Race, which keeps today's behaviour.
- `supps_ack_at DATETIME`: when the driver ticked the supplementary-regulations box for this entry.
- `entryTechTier(formats)` returns `race` if `race` is in the list, otherwise `ta_drift`. This is
  the only place the "strictest format" rule lives.
- Entries for **ice** events ignore `formats`, which stays `race`. Ice keeps its class-based flow.

### `cars`

`disciplines` gains the value `ta_drift` ("Summer TA/Drift only"). `CAR_DISCIPLINES`,
`carsValidateDetails` and `carSeasons()` accept it, and it counts as summer. A `ta_drift` car
isn't asked for a class declaration, and its entries default to TA.

### `tech_sheets`

- A new `sheet_type` value, `ta_drift`. Its `discipline` is `summer`, its `club` is the event's
  `host_club` (required), and it has no `submission_id`.
- `db_insert_tech_sheet`: add a `ta_drift` branch next to the ice one. It rejects a declaration,
  requires one of the user's cars, and requires the event to have a host club.
- A sheet can't change between `ta_drift` and another type. This works like the existing
  guard against moving a sheet between disciplines.
- `caged` (INTEGER 0/1) column: set on TA/Drift sheets and used to show the *(caged)* items.

### `gear_records`

Summer records start using `level`:

- `NULL`: race. This is what existing accepted summer gear has, so it needs no migration.
- `ta_drift`: TA/Drift level.

On accepting a summer record, the inspector picks **Race** or **TA/Drift**, defaulting to Race,
the same way they pick a level for ice. Rows stay unique on `(driver, discipline, season)`.

**Upgrading TA/Drift gear to Race uses the same record:**

- In person: the inspector accepts it again at Race.
- By photos: the driver submits the race gear photo list. The record's level stays `ta_drift`
  until an inspector accepts the photos at Race.

**The summer gear carry-over to ice** (summer gear from year Y counts as caged ice gear in Y+1,
`readinessIceGear`) applies only to **race-level** summer gear.

### `at_track_choices`

For TA/Drift car tech, the choice is stored as discipline `summer` with `club` set to the host
club. Summer race choices keep club `''`, so the two don't collide and no new values are needed.

### Status keys and the approval ladder (`tech-status.php`)

- `techCarKey`: a `ta_drift` sheet keys as `car|ta_drift|club|season`. Race summer keys stay
  `car|season`, and ice keys stay as they are.
- A new `taDriftCarTechStatus(raceStatus, taDriftStatus)` returns accepted if **either** is
  accepted (race wins as the label: "Teched 2026 (race)"). Otherwise it returns the TA/Drift
  sheet's state.
- Gear: `gearCoversTier(gear, tier)`. Accepted gear covers `ta_drift` at any level. It covers
  `race` only when `level` is `NULL`.

## 3. TA/Drift entry and sheet flow

### Entry (linking a car to an event)

- For a summer event, the car-to-event screen (Garage and Home) shows three checkboxes: Race,
  Time Attack and Drift. Defaults:
  - that car's formats from its most recent summer entry, or
  - TA for a `ta_drift` car, or
  - Race.
  At least one must be ticked.
- If the tier is `ta_drift`, the same screen shows the supplementary-regulations box and saves
  `supps_ack_at`.
- If the event has no host club, the TA and Drift boxes are disabled and a note says "This event
  has no host club yet. Ask an admin." (Summer events may still be saved without a host club.)
- A driver can change the formats later from the event card. Changing to Race clears nothing.

### Form (`tech-sheets.php?action=new-ta-drift` / `submit-ta-drift`, plus edit and update)

- Car (one of the user's cars), the host club (read-only, taken from the event), "Roll bar or
  cage fitted?", and the checklist.
- **Drivers**: driver 1 plus optional extra drivers, reusing `tech_sheet_drivers`. The sheet
  names everyone driving the car in TA or Drift at this event.
- Like race sheets, a TA/Drift sheet belongs to one event (`event_id`). Season approval comes
  from any accepted sheet with the same `techCarKey`.
- The gear self-declaration is filled in for each driver.
- Entrant and driver signatures, the same as the race sheet. There's no class, weight or HP.
- Files go in the pattern of the ice sheet: `ta-drift-sheet-lib.php` and
  `ta-drift-sheet-page.php`. The dispatchers `techSheetChecklistSections` and
  `techSheetEquipmentItems` gain a `ta_drift` branch.

### Review, acceptance and revoke

- Inspect lists TA/Drift sheets with a "TA/Drift" chip and the club. An inspector accepts in
  person (with a signature) or from photos, using the existing flows.
- Revoking now **requires a note**, for all sheet types and gear. The note is stored in new
  `revoke_note` columns on `tech_sheets` and `gear_records`, and cleared when the record is
  accepted again. It's shown to the owner on the car or driver ("Tech revoked: {note}").

## 4. Readiness, Home, Garage and reminders

### Readiness (`buildReadiness`)

The per-event branch becomes: ice → unchanged; summer → by `entryTechTier`:

- **race**: unchanged (declaration, sheet for this event, car tech for the year, and race-level
  gear for each driver).
- **ta_drift**:
  1. **Sheet for this event.** Once submitted, it's done. If not submitted, then:
     - if the car isn't approved (`taDriftCarTechStatus` isn't accepted), it's **todo**:
       "Submit a TA/Drift tech sheet for #N";
     - if the car is already approved, it's **suggested**: "Tech sheet for this event
       (recommended): you're teched for {club} {year}, but a sheet for each event helps the
       inspectors."
  2. **Car tech**: `taDriftCarTechStatus` for (car, host club, year), with an "I'll do it at the
     track" option.
  3. **Gear**: `gearCoversTier` at `ta_drift` for each driver. The drivers come from this event's
     TA/Drift sheet; otherwise from the most recent accepted TA/Drift sheet for this club and
     year; otherwise the entry owner's own driver.
  4. **Supplementary regulations**: ticked for this entry, or this event's sheet submitted.

  There's no declaration.

**New readiness state `suggested`:**

- `readinessItem` gains a third state next to `done` and `todo`.
- Home shows it in a softer style, with the action button.
- It doesn't count toward "N things to do" or the event's ready or not-ready status.
- Reminders leave it out.
- Only the TA/Drift per-event sheet uses it for now.

### Home, Garage and Drivers

- Event cards show the entry's formats ("TA · Drift").
- Garage car chips show "TA/Drift {club} {year}" next to the existing race and ice chips.
- `garageAfterAdd` for a `ta_drift` car goes to the event list rather than "Next, declare its
  class".
- The Drivers page shows the gear level ("Gear 2026: TA/Drift").

### Reminders

These come from readiness, so they get the TA/Drift items without their own code. Check that the
wording reads right for the new items.

## 5. MotorsportReg import and admin

- `MSR_RACE_TYPES` also keeps the MotorsportReg type names used for Time Attack and Drift.
  **Confirm the exact names from the NASCC and WSCC live feeds before coding.** The review page
  labels them "TA" or "Drift", and "Add to hub" creates a **summer** event with the host club.
- The admin Gear filter gains the level (Race or TA/Drift). The admin tech sheet list gains the
  "TA/Drift" type.

## 6. Testing (PHPUnit, `wcma-calculator/tests/`)

- `entryTechTier` for every combination of formats.
- `techCarKey` for `ta_drift`, and that `ta_drift` keys never collide with summer race or ice keys.
- `taDriftCarTechStatus`: race accepted covers it; a TA/Drift sheet at another club doesn't.
- `gearCoversTier`, and that the summer-to-ice carry-over ignores TA/Drift-level gear.
- The `db_insert_tech_sheet` `ta_drift` branch: it rejects a declaration and rejects an event
  with no host club.
- Readiness for a TA-only entry, a Race+TA entry, and an ice entry (unchanged).
- The per-event TA/Drift sheet is `todo` before the car is approved and `suggested` after. A
  `suggested` item isn't counted as outstanding and isn't reminded about.
- Submitting this event's sheet satisfies the supplementary-regulations item.
- Revoking requires a note.
- MotorsportReg: the TA and Drift types are kept and added as summer events.
- Phone-layout check: the car-to-event screen with formats, and the TA/Drift sheet form.

## Out of scope

- Classing TA/Drift cars (NASCC Street/Street+/Race, WSCC PIP classes), timing and results.
- Ice time attack. Ice Drift stays an ice class.
- Detecting substantial changes to a car automatically.
- Keeping per-club TA/Drift checklist wording (the ice rules have per-club overrides; this doesn't
  need them yet).
- Registration, which stays on MotorsportReg.
