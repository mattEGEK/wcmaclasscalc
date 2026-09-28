# Mobile UX for Older Users — Design Spec
**Date:** 2026-09-28
**Project:** WCMA Hub (221racing.com)
**Status:** Approved in conversation, waiting for review of this written spec.

---

## Overview

Most members are older and use the hub on a phone. A UX review (2026-09-28) walked a brand-new
member through the winter flow at 375px wide: sign up → add car → tag an ice event → ice tech
sheet → photo pre-tech. Screenshots are in `scratch/ux/` (not committed).

Pre-tech photos and no horizontal overflow (even at 150% text) are good. The problems are
wayfinding for winter drivers, controls that look disabled or are too small, and a long tech sheet
that loses progress. This spec fixes all 14 findings in three phases, each with its own plan,
branch and merge. **Phase A ships first** because winter is next.

| Phase | Findings | Theme |
|---|---|---|
| A | 1–5 | Winter wayfinding |
| B | 6–9 | Shared style pass |
| C | 10–14 | Tech sheet form |

### What the user asked for / decided

| Question | Decision |
|---|---|
| Which flow | The member flow: add car, complete tech. Winter first. |
| Scope | All 14 findings. |
| How a car says it's for ice | Stored on the car: **Ice / Summer / Both**, asked on add-car. |
| Keeping tech sheet progress | In the phone's browser (`localStorage`), not on the server. |
| Entrant = driver | One signature pad, saved as both signatures. |

### Assumptions (not stated by the user)

- Existing cars with no stored season keep today's inference.
- The single-signature and draft-save changes apply to the summer tech sheet too, since both forms
  share `js/tech-sheet-form.js` and `js/signature-pad.js`.
- Admin pages pick up the Phase B button styles automatically; no admin-specific layout work.

---

## Phase A — Winter wayfinding

### A1. Car season (`cars.disciplines`)

- New nullable column `cars.disciplines TEXT`, values `ice`, `summer`, `both`. Added through the
  existing `db.php` add-column migration helper. Null = unknown (all existing cars).
- New pure helper in `garage-lib.php`:
  `carSeasons(array $car, bool $inferredSummer, bool $inferredIce): array{summer: bool, ice: bool}`.
  Stored value wins; null falls back to the inferred flags (today's `garageCarUsesSummer()` /
  `garageIceSummary()` / tag logic).
- Garage cards, the car page, Home, readiness and `userUsesSummer()` / `userHasIceActivity()`
  consult `carSeasons()` instead of each re-deriving it. A car stored as `ice` never shows summer
  class or summer tech; a car stored as `summer` shows no ice chip unless it has ice sheets or an
  ice tag (activity still wins so nothing is hidden that exists).
- `cars-lib.php` validation accepts only the three values (or empty on edit of a legacy car).

### A2. Add a car

- Required question at the top of the form: **"Where will this car race?"** — three large
  radio-cards (Ice / Summer / Both), each ≥ 56px tall, whole card tappable.
- Year label becomes "Year (optional)"; car number gets `inputmode="numeric"`.
- The footer line "Next, you will declare its class…" is removed.
- `garage.php?action=add&event_id=N` pre-selects the season from event N and carries the event
  through, so submitting tags the car to it.
- **Edit details** shows the same question, pre-filled (blank for legacy cars; leaving it blank
  keeps inference).

After submit:

| Choice | Next screen |
|---|---|
| Ice, no event carried | "Car added. Which ice event is it going to first?" — upcoming ice events as full-width buttons (name, date, club), plus "Not sure yet" → car page. Picking one tags the car and redirects to `tech-sheets.php?action=new-ice&car_id=…&event_id=…`. |
| Ice/Both, event carried | Tag it; redirect to the matching tech step (ice sheet, or car page for summer). |
| Summer | Today's behaviour (car page, "Declare class"). |
| Both | Car page with both paths. |

### A3. Car page

- Ice-only car: no Class card and no red "No class declared yet".
- A **next-step card** sits directly under the car header:
  - not tagged to an ice event → "Pick your ice event" (the event buttons from A2);
  - tagged, no current ice sheet → "Submit ice tech sheet" (primary button);
  - sheet submitted → its status and the post-submit next steps (A5).
- "Bring this car to another event" → "Add this car to an event" ("another" only when it already
  has one). The dropdown lists only events matching the car's seasons.

### A4. Home and landing

- Every event card is a link:
  - no cars → `garage.php?action=add&event_id=N`;
  - one eligible car → that car's page, scrolled to the event form with the event pre-selected;
  - several cars → Garage with the event pre-selected.
  The card's text becomes "Add a car to say you're going →" / "Say you're going →".
- The Garage "At a glance" prompt follows the user's seasons: ice-only users see "Add your ice
  car" and never "declaring its class".
- Landing hero (signed-out): when the next upcoming event is ice, the text reads "Submit your ice
  tech sheet and track car and gear tech for the season.", Sign in / Create account are primary,
  and the Class Calculator becomes a secondary link. Otherwise unchanged.
- Hero "Meet the drivers" link: white and underlined (contrast fix, also Phase B).

### A5. After submitting a tech sheet

- Page title/heading: "Ice tech sheet — #42 2008 Honda Civic — NASCC Ice Race #1" (summer:
  "Tech sheet — …"). Not "Tech Sheet #2".
- On arrival from submit (flash), a green banner: "✓ Tech sheet sent to NASCC." (summer: "sent to
  WCMA").
- A **What's next** list, each a large button with one line of explanation, only showing steps that
  apply:
  1. Pre-tech with photos (optional) — skip inspection at the track.
  2. Driver gear — "Add gear photos" per driver without an accepted record, or "have it checked at
     the track".
  3. Register with the host club — link to the season's MotorsportReg link when one exists.
- Edit, Print and Resend email move below as secondary buttons. Resend is no longer primary.
- "Submitted — awaiting review" status moves to the top, next to the car status, as a chip.

---

## Phase B — Shared style pass

### B1. One button language (`css/hub.css`)

| Kind | Look |
|---|---|
| Primary | Solid `--hub-red`, white text, sentence case, ≥ 48px tall. |
| Secondary | White, 2px `--hub-ink` outline, `--hub-ink` text. |
| Disabled | Dashed `--hub-line` outline, `--hub-ink-2` text, `cursor: not-allowed`, and a visible reason line beneath (already present on pre-tech submit; add wherever a button can be disabled). |

- Legacy classes (`.btn`, `.btn-primary`, `.btn-secondary`, `button[type=submit]`, the pre-tech
  photo buttons) are remapped under `body.hub` so auth, tech sheet, pre-tech, gear and admin pages
  switch without markup changes. `text-transform: none` everywhere.
- Enabled controls are never grey.

### B2. Tap targets and text

- Buttons, link-buttons, selects, text inputs: ≥ 44px (inputs 48px). Input text ≥ 16px.
- Radios and checkboxes 24px; their label row is the tap target (≥ 44px).
- Signature "Clear" and password show/hide: 44px buttons.
- Header Profile / Sign out: padded to 44px. "Menu" button text 16px and fits its box.
- No member-facing text below 16px (the 12.8–13.6px rules found in the audit are raised).

### B3. Contrast (AA 4.5:1 minimum; 7:1 target for body text)

- Status words ("Photo needed", "Needs tech at the track", "No gear record") render as chips:
  `--hub-warn-ink` on `--hub-warn-bg`, `--hub-ok-ink` on `--hub-ok-bg`, red ink on `--hub-todo-bg`.
- Checklist OK / N/A and gear Confirm start as secondary buttons; when chosen they turn solid
  `--hub-ok-ink` with white text and a ✓ (N/A: solid `--hub-ink-2`).
- Links on dark backgrounds are white and underlined.

### B4. Automated audit

- `wcma-calculator/tests/ux/audit.mjs` (Playwright, Chromium, 375×800, mobile + touch), run
  against a local `php -S` server with a seeded throwaway DB (`DB_PATH` via auto-prepend, as in
  earlier e2e work).
- Pages: landing, register, login, Home, add car, car page, ice tech sheet (class chosen),
  submitted sheet, pre-tech.
- Fails on: a visible interactive element under 44px tall (skip-link and inline prose links
  excepted), input text under 16px, horizontal overflow, and button/status-chip contrast under
  4.5:1.
- `README`-level note on how to run it; not part of the PHPUnit suite.

---

## Phase C — Tech sheet form

### C1. Draft save (`js/tech-sheet-draft.js`)

- Saves on every change: weight, class, HP, engine fields, checklist answers, gear items and
  ratings, log book. Not signatures, not files.
- Key: `wcma-tsdraft:<userId>:<carId>:<eventId>`; value includes a timestamp. Drafts older than 14
  days are ignored and deleted.
- On load (new sheet only, not edit), a stored draft is restored and a notice shows: "We kept your
  answers from earlier." with a **Start over** button (clears the draft and resets the form).
- Cleared on successful submit (the submitted page's JS removes the key, since the redirect means
  success).
- All storage access in try/catch; without storage the form behaves as today.
- Pure save/restore/expiry logic unit-tested in `tests/js` like the existing JS tests.

### C2. Empty states

- Before a class is chosen, the checklist card reads "Choose your class above and its checklist
  appears here." No "0 of 0 items checked" or empty progress bar.
- The form error box is hidden (`hidden` attribute) until it has text.

### C3. Required fields and errors

- Required fields: "(required)" in the label text. Optional: "(optional)".
- On a failed submit (native or JS validation), scroll to and focus the first problem and show an
  inline message under that field in words ("Enter the race weight"), not only the browser bubble.

### C4. One signature when entrant = driver

- When the selected driver is the entrant (the signed-in user as their own driver), show one pad
  labelled "Your signature (entrant and driver)". Switching to another driver shows both pads.
- The client copies the single signature into both hidden fields, so the server contract, stored
  paths, printed form and inspector view are unchanged. Server validation stays as it is.
- Missing-signature error moves above the pads and names the box: "Please sign in the Driver's
  signature box."

### C5. Submitted sheet labels

- Ice season shows as "Winter 2026–27" (display helper; stored `season` unchanged).
- Engine line shows only filled parts: "140 HP", "1998 cc", or "1998 cc / 140 HP".
- Gear rows stack the item name above its rating input under 480px wide.

---

## Testing

- PHPUnit for the migration, `carSeasons()`, add-car validation and redirects, Home event links,
  post-submit next steps, season label and engine line helpers.
- `tests/js` for draft storage and single-signature copying.
- Phase B audit script (B4) run before each phase merges, plus a manual pass at 375px and 150%
  text on the flow pages.

## Out of scope

- Server-side tech sheet drafts.
- Ice class finder ("Help me pick") — its own spec.
- Admin/inspector layout redesign beyond inheriting B's button styles.
