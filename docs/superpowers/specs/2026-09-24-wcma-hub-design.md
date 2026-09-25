# WCMA Hub — Design Spec
**Date:** 2026-09-24
**Project:** WCMA Classing Calculator (221racing.com), becoming the WCMA Hub
**Status:** Approved design, pending spec review. Each phase below gets its own implementation plan.

---

## Overview

The app is no longer just a classing calculator. It now covers class declaration, per-event tech sheets, car pre-tech and driver gear, and more will follow. The UX is still organized around the calculator, though. `index.php` redirects to the classing form, the nav lists features rather than a structure, and the account page ("My Cars") is really a list of class declarations.

This spec reorganizes the app into the **WCMA Hub**, centred on the driver account. It covers:

1. A proper core model: **Cars** and **Driver profiles** as first-class records that declarations, tech sheets and gear hang off.
2. A signed-in **Home** that answers "what do I still need to do for the events I'm going to?"
3. **Garage**, **Drivers**, and a re-homed **Class Calculator**.
4. An **Inspector** role and section, including a new review workflow for class declarations.
5. A shared page layout and visual identity.
6. Opt-in **email reminders**.

### Context that shapes the design

- **WCMA is the sanctioning body.** Race weekends are hosted and registered by affiliated clubs. The hub does not host an event calendar or handle entries.
- **MotorsportReg (MSR) handles** the annual waiver/hardcard, all licence types, and car classing and number reservation. The hub links to MSR and does not integrate with it. MSR items get new URLs every season (e.g. `https://www.motorsportreg.com/events/wcma-2026-basic-race-license-…-313932`), so links are admin-maintained data, not code.
- **Audience:** competitors skew older and are less comfortable with web forms (see `2026-09-22-competitor-flow-clarity-design.md`). Inspectors use the app on phones at the track.
- **Terminology rule (binding, from `2026-09-23-digital-tech-inspection-design.md`):** use *reviewed*, *accepted* and *pre-teched*, never *approved*, *passed* or *safe*. This spec extends the rule to class declarations.
- **Not blocking (unchanged):** a tech sheet can always be submitted, whatever the car, gear or declaration status. Status is shown, and inspectors decide at the track.

### Out of scope

- A public event calendar, club directory, or event registration.
- An MSR API integration. Licence status is not tracked, only linked.
- A UI for claiming driver profiles. The data model supports it (`drivers.user_id`); the UI is a later project.
- Results, points, and licence/medical tracking.

---

## 1. Information architecture

### Primary nav (signed in)

Text labels only, never icon-only:

**Home · Garage · Drivers · Class Calculator**

Staff sections follow after a divider: **Inspector** (inspector, admin) and **Admin** (admin only). Each has second-level tabs built from the same nav component.

The account menu (top right) holds Profile (name, email, password/Google, reminder emails) and Sign out. The footer holds Feedback, Sporting & Technical Regulations, and wcma.ca.

On phones the nav collapses to a single labelled **Menu** button.

### Anonymous visitor

`index.php` becomes a public landing page with:
- one line on what the hub is
- **Class Calculator** (fully usable without an account)
- Sign in / Create account
- This season on MotorsportReg

### One flow, several entry points

- **Tech sheet:** reachable from a Home todo, the car page, or the car's event list. It's always the same form, with car and event filled in from where you started.
- **Class declaration:** "Re-declare class" on a car opens the calculator bound to that car. Declaring with no car bound asks which car it's for at submit.

### URL map

| Page | URL | Replaces |
|---|---|---|
| Landing / Home | `index.php` | redirect to calculator |
| Garage | `garage.php`, `garage.php?car={id}` | `account.php` (redirects) |
| Drivers | `drivers.php` | `gear.php` list (redirects); gear pre-tech pages move under it |
| Class Calculator | `calculator.php` (`?car={id}`) | `car-classing.html` (redirects, since wcma.ca links to it) |
| Declaration POST handler | `car-classing.php` (unchanged name) | — |
| Tech sheets | `tech-sheets.php` (unchanged) | — |
| Inspector | `inspect.php` | admin Submissions, Tech Sheets, Gear |
| Admin | `admin.php` | back office only |
| Profile | `profile.php` | — |

---

## 2. Core data model

### New tables

```sql
cars (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_user_id INTEGER NOT NULL,
  car_number TEXT NOT NULL,
  car_number_norm TEXT NOT NULL,     -- trim, uppercase, strip leading zeros unless all zeros (existing rule)
  year TEXT, make TEXT NOT NULL, model TEXT NOT NULL,
  colour TEXT, engine_cc TEXT,
  archived_at DATETIME,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
)

drivers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_user_id INTEGER NOT NULL,    -- the account that manages this profile
  user_id INTEGER UNIQUE,            -- the account this person is; set for the self profile, later via claim
  name TEXT NOT NULL, name_norm TEXT NOT NULL,
  licence_no TEXT,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
)

event_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL, event_id INTEGER NOT NULL, car_id INTEGER NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE (event_id, car_id)
)

season_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL, url TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1
)
```

Car numbers are **not** unique across owners. Numbers are reserved on MSR, and the hub records what the owner enters.

### Changes to existing tables

| Table | Change |
|---|---|
| `submissions` (class declarations) | + `car_id`, `review_status` (`submitted` \| `accepted` \| `needs_changes` \| `superseded`), `reviewer_note`, `reviewed_by_user_id`, `reviewed_at`, `accepted_via` (`review` \| `legacy`) |
| `tech_sheets` | + `car_id`. Car identity fields stay as a **snapshot** taken at submit (a sheet is a signed record), filled from the car. |
| `tech_sheet_drivers` | + `driver_id` |
| `gear_records` | + `driver_id`. Uniqueness becomes `(driver_id, season)`. |
| `users` | `role` gains `'inspector'`; + `reminder_emails INTEGER NOT NULL DEFAULT 0` |

### Identity rules

- **Car tech status** is derived per `(car_id, season)` instead of per owner + normalized number + year. The status precedence in `tech-status.php` is unchanged; only the grouping key changes.
- **A car's class** is its newest non-superseded declaration, together with its `review_status`. If that declaration isn't accepted and an earlier accepted one exists, both are shown ("GT3 · With an inspector — Accepted: GT2").
- **Deleting:** a declaration can be deleted individually. A car is **archived**, never deleted, so its tech history survives. Archived cars are hidden from Home and pickers.
- **Self driver profile:** every account has exactly one driver with `user_id = owner_user_id = account id`. It's created on first sign-in (and by the migration for existing accounts).

### Migration (phase 1)

It runs from `db_init`, is idempotent, and runs inside one transaction per step. A CLI **dry run** (`php migrate-hub.php --dry-run`) prints counts and ambiguous cases before the real run.

1. **Cars.** For each user, group their submissions into cars. Submissions whose tech sheets share a normalized car number become one car. A submission with no sheets becomes its own car. The car record takes year/make/model from the group's newest submission, and number/colour/engine from its newest sheet. If there's no number, it gets a placeholder `?` and is flagged in the dry run.
2. `tech_sheets.car_id` comes from its submission's car.
3. **Drivers.** Create a self profile per user. Each distinct `name_norm` across the user's `gear_records` and `tech_sheet_drivers` becomes a managed profile, except that a name matching the user's own `name` maps to the self profile. Then set `gear_records.driver_id` and `tech_sheet_drivers.driver_id`.
4. **Anonymous submissions** whose email matches an account are attached to it and grouped as in step 1. The rest stay `user_id NULL` and are visible to inspectors and admins only.
5. **Declaration review status:** each car's newest 2026 declaration → `submitted` (it enters the review queue). All other declarations → `accepted` with `accepted_via = 'legacy'`, except older declarations of the same car, which become `superseded`.

**Known risk:** step 1 can split one real car into two. The fallback is an admin **Merge cars** tool. It moves declarations, sheets and plans onto the surviving car and archives the other.

---

## 3. Home and readiness

### Event tagging

Competitors tag the events they're going to, per car: **"I'm bringing #42 to Fall Sprint."** Events themselves are still the short admin-maintained list of active events, as today. Tagging:
- only drives the competitor's checklist and reminders
- does **not** register them, and the copy says "This doesn't register you. Register with the host club."
- can be undone ("Not going anymore").

With one car, **I'm going** tags immediately. With several, it opens a car picker.

### Readiness engine

`buildReadiness(array $cars, array $drivers, array $plans, array $events, array $declarations, array $sheets, array $gearRecords, array $photoSets, int $season): array` is a pure function with no DB access and no HTML, like `buildCarTechSheetGroups()`. For each **tagged** event, soonest first, it returns items with:
`kind`, `subject_type` (`car` | `driver`), `subject_id`, `state` (`todo` | `info` | `done`), `label`, `detail`, `action_url`, and an optional `secondary_action`.

For each tagged car × event:

| Check | done | info | todo |
|---|---|---|---|
| Class declaration | newest is `accepted` | `submitted` ("With an inspector") | none this season → "Declare class for #42"; `needs_changes` → "Your class declaration for #42 needs changes" |
| Tech sheet | a sheet exists for car + event | — | "Submit a tech sheet for #42" |
| Car tech (season) | accepted, or the competitor chose **I'll do it at the track** | photos `submitted` | not accepted → "Car tech for #17: pre-tech with photos, or bring it to tech at the track" (primary **Add photos**, secondary **I'll do it at the track**); `needs_changes` → "Retake N photos for #17" |
| Gear, per driver | same as car tech | same | same |

- **Drivers for a car × event** come from the tech sheet's drivers if a sheet exists, otherwise from the owner's self profile.
- **"I'll do it at the track"** is stored per `(car|driver, season)` in a small `at_track_choices` table: `subject_type`, `subject_id`, `season`, `created_at`, unique on those three. It is **planning only**. It does not accept anything, and the roster still shows the item as needing tech. A later photo submission overrides it.
- **Seasonal items** (class, car tech, gear) appear under the **nearest** tagged event only. Tech sheets appear under every tagged event.

### Home layout (signed in)

1. **Headline:**
   - "N things to do before ⟨event⟩", or
   - "You're all set for ⟨event⟩ ✓", or
   - with nothing tagged: "Which events are you going to?"
2. **Numbered todo list**, one primary button each.
3. **With an inspector** (info items).
4. **Already done (n)**: open on desktop, collapsed on phones.
5. **Upcoming events: are you going?** Untagged active events with **I'm going**.
6. **At a glance:** a Garage summary (plate · car · class + status · car tech status) and a Drivers summary (name · gear status).
7. **This season on MotorsportReg** (active `season_links`).
8. "Next after that: ⟨event⟩, N things to do."

**Empty states:**
- No cars: "Start by adding your car and declaring its class" → Add a car.
- No active events: "No upcoming events yet", plus any seasonal todos.

### Mockups

Chosen direction: layout from option C, visual identity from option B. Files are in `scratch/hub-mockups/` (untracked): `c-todo-*.png` and `b-garage-*.png`, with HTML sources alongside. Option C's mockup came before event tagging, so it doesn't show the "are you going?" list.

---

## 4. Garage, Drivers, Calculator, Tech sheet form

### Garage (`garage.php`)

- **List:** a card per active car (round number plate, year/make/model, class badge + review status, car tech status, next tagged event status). Plus a **+ Add a car** card and a collapsed **Archived** group.
- **Add a car:** number, year, make, model, colour (engine optional). It's followed by "Declare its class" → `calculator.php?car={id}`. The form links to MSR "Car Classing & Number Reservation" if that season link exists.
- **Car page (`garage.php?car={id}`):**
  - **Details:** edit inline.
  - **Class:** current declaration (class, weight, HP, date, review status and note) with **Re-declare class** and **View**. History below.
  - **Car tech {season}:** status, with **Add / retake photos** (existing pre-tech flow).
  - **Events:** tagged events with tech sheet status and **Submit / View**, plus **Bring this car to another event**.
  - **Archive car**, with a confirmation.

### Drivers (`drivers.php`)

- **You** first: name, licence number (free text, with "Licences are managed on MotorsportReg" and a link), and this season's gear status with **Add photos / View**.
- **Co-drivers you manage:** same row shape; **Add a co-driver** (name, optional licence).
- Seasons roll over automatically. A driver with no gear record for the current season shows "Needs gear tech", and the gear record is created the first time photos are added or an inspector accepts in person. The manual **Renew** step is removed.

### Class Calculator (`calculator.php`)

- A PHP shell around the existing calculator markup, rendered in the shared layout. The JS-built nav in `ui-controller.js` is removed.
- **The real-time recalculation behaviour must not change.**
- **Signed in, `?car={id}`:**
  - banner "Declaring class for #42 2004 Honda S2000"
  - inputs pre-filled from the car's current declaration
  - name/email/year/make/model hidden (they come from the account and the car).
- **Signed in, no car:** at submit, a "Which car is this for?" step lists your cars or **New car** (number, year, make, model, colour).
- **Anonymous:** the calculator works fully. Submitting prompts sign in / create account. Form state is saved to `sessionStorage` (wrapped in try/catch), restored after auth, then goes through the which-car step.
- **After submitting:** the declaration is `submitted`, and the confirmation says an inspector will review it.

### Tech sheet form

- Car and event are chosen up front, or pre-filled. The picker lists only non-archived cars.
- Car number/make/model/colour are **read-only from the car**, with **Edit car details**. They're snapshotted onto the sheet at submit.
- Driver rows pick from your driver profiles, with **Add a co-driver** inline. Driver 1 defaults to your self profile.
- Submitting a sheet for an untagged event tags it automatically.

---

## 5. Inspector and Admin

### Roles

- `users.role` ∈ `user`, `inspector`, `admin`.
- `is_inspector()` is true for inspector and admin.
- All authorization goes through `require_role(string $min)`.
- Admin → Users uses a role dropdown, and the existing last-active-admin guard stays.

### Declaration review workflow

- **Inspector actions:** **Accept**, or **Send back** with a required note (→ `needs_changes`). Each emails the competitor through the existing email helpers.
- **Acceptance email footer:** "Acceptance confirms that what you submitted matches what was reviewed. It is not a certification that the vehicle is eligible or safe."
- **The competitor responds by re-declaring** (calculator pre-filled from the queried declaration). This creates a new `submitted` declaration, and the previous one becomes `superseded`.
- **Not blocking:** tech sheets stay submittable. The roster shows declaration status.

### Inspector section (`inspect.php`, phone-first)

| Tab | Content |
|---|---|
| **Event roster** (default) | Event picker (defaults to the next active event). A row per car that is tagged or has a sheet: plate, car, class + declaration status, tech sheet ✓/✗, car tech status, gear chips per driver. Filters: *Needs decals*, *Needs tech at track*, *No sheet yet*, *Class not accepted*. Existing in-person accept and one-tap gear create-and-accept are reused. |
| **Review queue** | One list, oldest first: declarations (`submitted`), car photo sets, gear photo sets. Existing review cards are reused; a new declaration review card shows the declaration detail with its files. |
| **Classing** | All declarations: search and filter by class, season, status, car. Detail page with files, car, owner. Admins also see delete, bulk delete, edit contact. |
| **Gear** | Season gear list (existing). |

### Admin section (`admin.php`, back office)

Users & roles · Events (with tagged-car counts) · Season links · Settings · Feedback · Merge cars.

---

## 6. Visual system and page layer

### Visual identity (from mockup B)

- **Type:** Archivo (body) and Archivo Narrow (headings, plates), loaded from Google Fonts with system fallbacks.
- **Tokens:** red `#d2151e`, ink `#111317`, ink-2 `#555b64`, paper `#f2f3f5`, card `#fff`, ok `#2fae5f`/`#17703a`, warn `#f0a400`/`#7d5500`, todo `#e2323a`.
- **Motifs:** red/black stripe under the header, round number plates, black class badges, status dot + **word** (never colour alone).
- **Accessibility floor:** 18px base text, 44px minimum tap targets, visible focus rings, WCAG AA contrast for all text.

### Page layer

- **`layout.php`:** `renderPageStart(string $title, string $section, array $subnav = [])` and `renderPageEnd()`. They emit the head, header, stripe, primary and staff nav, account menu, flash message and footer. They replace `renderSiteHeader()`, `renderCommonNav()` and `renderAdminNav()`.
- **`css/hub.css`:** tokens and shared components (nav, buttons, cards, plate, class badge, status, todo list, tables, forms). `css/calculator.css` shrinks to calculator-only rules.
- **Inline styles** are removed from each page as it's rebuilt.

---

## 7. Reminders (opt-in)

- `users.reminder_emails`, default **off**, for new and existing users.
- The first time a user tags an event, a checkbox offers: "Email me reminders for events I'm going to." The same toggle is in Profile.
- **Daily cron** (`reminders.php`, CLI only): for each opted-in user with tagged events **14, 7 or 2 days** out and at least one `todo` item from `buildReadiness`, send one digest per event. No email is sent if there are no todos.
- `reminder_log (user_id, event_id, days_out, sent_at, UNIQUE(user_id, event_id, days_out))` prevents duplicate sends.
- **Unsubscribe:** every email has a one-click unsubscribe link (signed token), which clears `reminder_emails`.

---

## 8. Phasing

Each phase gets its own implementation plan and ships on its own.

| Phase | Delivers |
|---|---|
| **1. Core model** | New tables and columns, the `inspector` role, `require_role()`, the migration with dry run. Existing pages switched to `car_id`/`driver_id` with minimal UI change. Admin: role dropdown, Season links editor, Merge cars. Declarations get `review_status` (review UI lands in phase 4; until then new declarations are stored as `submitted`). |
| **2. Shell and Home** | `layout.php`, `hub.css`, public landing page, signed-in Home with `buildReadiness`, event tagging and "I'll do it at the track". `calculator.php` with car binding, the which-car step and sign-in-to-submit. `profile.php`. |
| **3. Garage and Drivers** | `garage.php`, `drivers.php` (old URLs redirect). Tech sheet form picks cars and driver profiles. |
| **4. Inspector** | `inspect.php`: roster, unified review queue with declaration review and emails, classing, gear. Admin trimmed to back office. |
| **5. Reminders** | Opt-in flag and prompt, `reminders.php` cron, `reminder_log`, unsubscribe. |

---

## 9. Testing and error handling

- **Unit tests (PHPUnit)** for the pure functions: `buildReadiness`, migration car grouping and driver matching, declaration status and supersede rules, `reminderDue`, car number normalization (existing).
- **Migration:** tested against a fixture DB with the production schema's shape, covering shared numbers, sheetless submissions, anonymous submissions, and names matching the account holder. It must be idempotent (running it twice gives no changes).
- **End-to-end:** one Playwright harness per phase, following the existing `scratch/tech*-e2e.js` pattern.
- **Calculator regression:** an e2e check that changing weight, HP and each modifier still updates ratio and class live.
- **Errors:**
  - Migration steps are transactional, and a failed step rolls back and logs.
  - A failed email never blocks the action it follows (existing pattern), and the flash message says the email could not be sent.
  - An authorization failure redirects to sign-in (anonymous) or shows a 403 page inside the layout (signed in).
