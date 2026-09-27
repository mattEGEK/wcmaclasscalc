# Driver Media Profiles — Design Spec
**Date:** 2026-09-27
**Project:** WCMA Hub (221racing.com)
**Status:** Approved in conversation, waiting for review of this written spec.

---

## Overview

Drivers can add a **media profile**: a photo, a short blurb, a few optional facts, and their
sponsors. Affiliated clubs use it in two ways: **announcing** at events and **promotion** (social
posts, programs). With a separate opt-in, the profile can also appear on a **public web page**.
Nothing is used without the driver's recorded consent.

It adds three outputs:

- **A. Announcer sheet:** each event's roster, with profiles, for the PA announcer.
- **B. Media kit:** text ready to copy, and photos, for club social media volunteers.
- **C. Public driver page:** one shareable page per driver on the open web.

### Context that shapes the design

- **Drivers don't fill things in.** Keep required fields to a minimum. Never block anything on a
  media profile. Nudge drivers once and then leave them alone.
- **Profiles build on the existing `drivers` records** (Drivers page). Many are co-drivers managed
  from someone else's account, and there's still no screen for claiming a profile.
- **Events are linked to cars, not drivers** (`event_plans` is keyed on `event_id, car_id`).
  Drivers are only linked to a car through tech sheets (`tech_sheets.driver_id`,
  `tech_sheet_drivers`).
- **Roles are a single ladder** (`ROLE_LEVELS`: user < inspector < admin). Media staff are a
  separate group of people, so Media is a flag on the user, not a rung on that ladder.
- **Terminology rule (binding):** *reviewed*, *accepted* and *sent back*, never *approved*.

### Decisions made while brainstorming

| Question | Decision |
|---|---|
| Outputs | A, B and C |
| How consent is split | Two levels: (1) announcing and club promotion; (2) the public page, as a separate opt-in |
| Minors | A parent/guardian consent box with the guardian's name |
| Who sees A and B | A new **Media** staff flag, which admins switch on (admins always have access) |
| Co-drivers without an account | The account owner confirms consent on the driver's behalf, and the record says so |
| Moderation | A and B use a profile as soon as it's saved; C shows only after Media accepts it; Media can hide any profile |
| Sponsor logos | Not included. Sponsors are a name and an optional link |

### Out of scope

- Sponsor logos, and generated social graphics or images.
- A public directory or search listing all drivers.
- Emailing consent links to co-drivers, or claiming profiles.
- Keeping the previously accepted public version live while an edit waits for review.
- Pulling any profile data from MotorsportReg (see `docs/backlog.md`).

---

## 1. Data model

New tables are added to `db.php` alongside the existing `CREATE TABLE IF NOT EXISTS` blocks.

```sql
driver_media_profiles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  driver_id INTEGER NOT NULL UNIQUE,
  blurb TEXT NOT NULL DEFAULT '',          -- 500 characters at most
  pronunciation TEXT,                      -- 60 at most
  hometown TEXT,                           -- 60 at most
  racing_since INTEGER,                    -- a year from 1950 to the current year
  social_handle TEXT,                      -- 60 at most, stored without a leading @
  photo_path TEXT,                         -- relative to uploads/, e.g. media/driver-42-<rand>.jpg
  public_status TEXT NOT NULL DEFAULT 'none',  -- none | pending_review | accepted | sent_back
  public_reviewed_by INTEGER, public_reviewed_at DATETIME, public_note TEXT,
  hidden_at DATETIME, hidden_by INTEGER, hidden_reason TEXT,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
)

driver_sponsors (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  driver_id INTEGER NOT NULL,
  name TEXT NOT NULL,                      -- 80 at most
  url TEXT,                                -- http(s) only, 200 at most
  sort_order INTEGER NOT NULL DEFAULT 0
)                                          -- 6 per driver at most; replaced as a set on save

media_consents (                           -- rows are only ever added; the newest row per driver is current
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  driver_id INTEGER NOT NULL,
  consent_media INTEGER NOT NULL,          -- 0/1: announcing and club promotion
  consent_public INTEGER NOT NULL,         -- 0/1: public page (only meaningful if consent_media = 1)
  is_minor INTEGER NOT NULL DEFAULT 0,
  guardian_name TEXT,                      -- required when is_minor = 1
  given_by_user_id INTEGER NOT NULL,
  on_behalf INTEGER NOT NULL DEFAULT 0,    -- 1 = the account owner confirmed for a co-driver
  wording_version INTEGER NOT NULL,
  created_at DATETIME NOT NULL
)
CREATE INDEX idx_media_consents_driver ON media_consents (driver_id, id)
```

Changes to `users`: add `is_media INTEGER NOT NULL DEFAULT 0` and
`media_prompt_dismissed INTEGER NOT NULL DEFAULT 0`.

The schema change follows the same route as the hub phases: `reset-hub-db.php` and the seed data
are updated. The seed adds one fully consented driver, one without consent, one minor, and one
public profile waiting for review.

## 2. Consent rules (`media-lib.php`, pure functions)

- `CONSENT_WORDING_VERSION` and the exact wording of each checkbox are constants in
  `media-lib.php`. Changing the wording means bumping the version. Existing consent records stay
  valid, because each one keeps the version it was given under.
- `mediaCurrentConsent(?array $latestRow)` returns `{media: bool, public: bool}`. With no row,
  both are false. `public` is true only if `media` is also true.
- **When a profile can be used in each output:**
  - announcer sheet and media kit: `media` is true, **and** the profile isn't hidden, **and** it
    has a photo or a blurb that isn't empty;
  - public page: all of the above, **and** `public` is true, **and** `public_status` is
    `accepted`.
- **Withdrawing** adds a row with both consents at 0. It takes effect immediately, because every
  output reads the newest row.
- **Minors:** ticking the minor box makes the guardian name required, and the consent wording
  reads as given by the parent or guardian.
- **On behalf:** a driver row that isn't the account's own profile (`drivers.user_id` isn't the
  current user) needs the box *"I confirm [name] agreed to the above"*, and the record gets
  `on_behalf = 1`.
- A new consent row is written only when the consent inputs change, not on every save.

## 3. Driver side

### Drivers page
Each driver row gets a media status, worked out by `mediaProfileStatus()`:

| Status | When |
|---|---|
| Not set up | No profile, or no media consent |
| Shared with clubs | Media consent given, public page not requested |
| Public page: waiting for review | Public page requested, `public_status` = `pending_review` |
| Public page live | Public page requested and accepted |
| Public page sent back | `sent_back` |
| Hidden by WCMA | `hidden_at` is set |

The action link opens `media-profile.php?driver_id={id}`.

### `media-profile.php`
- **Access:** signed in, and `drivers.owner_user_id` is the current user. Otherwise a 404.
- **The form, top to bottom:**
  1. Photo: upload or replace, with a preview. It uses `js/photo-resize.js` (1600px longest
     edge in the browser), and the server checks it with `inspectionValidateImage()` (JPEG, PNG
     or WebP, 2 MB and 4000px at most). The file is saved as `uploads/media/driver-{id}-{random}.{ext}`
     and the old file is deleted when it's replaced.
  2. Blurb: a live counter up to 500, with the hint "Written so an announcer can read it out."
  3. About (optional): pronunciation, hometown, racing since, social handle.
  4. Sponsors (optional): six rows of name and website. Blank rows are ignored, and a URL without
     a name is an error.
  5. Consent checkboxes, as described in §2, next to Save.
- **Saving:**
  - Drafts are fine: without media consent the profile is saved but not used.
  - Saving a profile whose `public_status` is `accepted` or `sent_back` with changes to the photo,
    blurb, sponsors or about fields sets it to `pending_review`.
  - Ticking the public page box on a profile whose status is `none` also sets it to
    `pending_review`.
  - Unticking the public page box sets it back to `none`.
- **Withdraw consent:** a POST with a confirmation step. It adds a consent row with both at 0, and
  the profile data is kept.
- **Delete profile:** removes the photo file, sponsors and profile row. Consent rows are kept as
  the record.
- A "View public page" link appears when the public page is live.
- POST forms are CSRF-checked, following the existing pattern.

### Home prompt
One card for signed-in users whose own driver profile has no media consent and whose
`users.media_prompt_dismissed` is 0: *"Clubs would like to feature you — add a photo and a line
about yourself."* It has two actions, **Add profile** and **No thanks**; No thanks sets the flag.
It isn't part of readiness or the to-do count, and doesn't go in reminder emails.

## 4. Media section (`media.php`)

- **Access:** `is_media = 1` or the admin role, checked by a `mediaCanAccess(?array $user)` helper
  in `roles.php`. A **Media** entry appears in the staff part of the navigation for those users.
- Three tabs, built with the existing section-tab component.

### Announcer tab
- An event picker: active events with upcoming ones first, then the most recent past ones.
- The roster is every car tagged for the event (`event_plans`) plus every car with a tech sheet
  for the event, in the same order as the inspector roster (numbers first, then text).
- **Which drivers each car shows** (`mediaRosterDrivers()`), using the first source that has any:
  1. drivers on this event's tech sheets for the car (`tech_sheets.driver_id` and
     `tech_sheet_drivers.driver_id`);
  2. drivers on the car's most recent tech sheet;
  3. the car owner's own driver profile.
- **Each entry shows:**
  - large type: car number, then car (year, make, model, colour) and class, where class comes
    from the event's tech sheet or else the car's accepted declaration;
  - the driver name and pronunciation;
  - if the profile is usable for this output: photo, hometown, racing since, blurb, sponsors.
    Otherwise a muted "No media profile".
- **Add a driver:** a search box finds any consented driver by name and adds them to the list for
  this page view only. Nothing is saved.
- The layout is built for phones, with a print stylesheet (one car per block, no navigation).

### Media kit tab
- Filter: **All consented drivers** (the default) or **Drivers at an event** (the announcer
  roster, profiles usable in this output only).
- **Each driver card:** photo, a "Download photo" link, and **Copy text**, which copies text from
  `mediaCopyText()`:
  ```
  #42 Jane Doe — 2004 Honda S2000
  Red Deer, AB · Racing since 2015
  <blurb>
  Supported by: Acme Tires, Bob's Garage
  ```
  Empty parts are left out.
- **Download all (.zip):** photos named `{number}-{name-slug}.{ext}` plus a `profiles.csv` with
  number, name, pronunciation, hometown, racing_since, car, class, blurb, sponsors, social handle
  and public URL (if live). The button only appears if `class_exists('ZipArchive')`.

### Public review tab
- **Queue:** profiles with `pending_review`, public consent on, and not hidden, oldest
  `updated_at` first. Each shows the photo, blurb, about fields and sponsors with their links.
- **Accept:** sets `accepted` and records the reviewer and time.
- **Send back:** sets `sent_back` with a required note and emails the account owner.
- **Hide / Unhide:** available on any profile, found by a name search on this tab. Hiding needs a
  reason, removes the profile from all three outputs, and emails the owner. Unhiding clears it and
  leaves `public_status` as it was.

### Photo serving
Photos are never served straight from `uploads/`. `media-photo.php?driver_id={id}` streams the
file after checking that one of these is true:
- the viewer can access the Media section, or the viewer owns the driver profile;
- or the profile is usable on the public page.

Anything else gets a 404. `uploads/` is already blocked from direct access; confirm that when
building.

## 5. Public page (`driver.php?id={id}`)

- **Shown only if** the profile is usable on the public page (§2). Otherwise a plain page saying
  "This profile isn't available", with HTTP 404, whatever the reason.
- **Content:**
  - photo, name, hometown and "racing since";
  - this season's car and number, from the driver's most recent tech sheet this season (left out
    if there isn't one);
  - the blurb;
  - sponsors, linked with `rel="sponsored noopener"` and `target="_blank"`;
  - the social handle.
- Uses the public layout (the same shell as the landing page). Search engines may index it.
  There's no directory page.

## 6. Admin

The Users & roles screen in `admin.php` gets a **Media staff** checkbox next to the role picker.
It's a POST action that is CSRF-checked and admin-only.

## 7. Email

Two new messages go through the existing email helpers to the driver profile's owner account:
- *Your public driver page was sent back*, with the note and a link to `media-profile.php`.
- *Your driver profile was hidden*, with the reason and a link to `media-profile.php`.

Accepting a profile sends no email, so as not to add noise.

## 8. Testing (PHPUnit)

- **Consent:** the current consent with no rows, the newest row winning, public requiring media,
  withdrawal, a minor requiring a guardian, on-behalf required for co-drivers, and no new row when
  nothing changed.
- **Whether a profile can be used in each output:** hidden overrides everything, and a profile
  needs a photo or a blurb.
- **Public status changes:** an edit to an accepted profile goes back to `pending_review`,
  unticking the public box goes to `none`, and hiding and unhiding keep the status.
- **`mediaRosterDrivers()`:** each of the three sources, and the order they're tried in.
- **`mediaCopyText()`:** all fields filled in, and empty parts left out.
- **Validation:** field lengths, the racing-since range, sponsor URL scheme, the maximum of 6
  sponsors, and a name being required.
- **Access:** `media-profile.php` for the owner vs. anyone else, `mediaCanAccess()`, and
  `media-photo.php` for each kind of viewer.
