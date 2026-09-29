# MotorsportReg Calendar Import — Design Spec
**Date:** 2026-09-29
**Project:** WCMA Hub (221racing.com)
**Status:** Approved (2026-09-29): design section by section, written spec reviewed.

Hub events are typed in by admins today, and each event's MotorsportReg link (`events.msr_url`,
added 2026-09-29) is pasted by hand. MotorsportReg (MSR) publishes every club's
event calendar as a public JSON feed. This feature reads those feeds daily and lets an admin add
race events to the hub with one click, and flags later changes (moved, renamed, cancelled).

This is a scope change to the hub spec (`2026-09-24-wcma-hub-design.md`), which ruled out MSR
integration. It uses only the public, no-login calendar feed, and it is admin-only: drivers never
see feed data except through hub events an admin created. Backlog item "MotorsportReg API
integration", step 1.

---

## 0. What the feed gives (checked live 2026-09-29)

- `GET https://api.motorsportreg.com/rest/calendars/organization/{orgId}.json` — no login, no
  Accept header needed (the `.json` extension picks the format). Upcoming events only by default
  (`archive=true` adds past ones; not used). Response: `response.events[]`, each with `id`, `name`,
  `start`, `end` (`YYYY-MM-DD`), `type`, `cancelled`, `detailuri`, `uri`, `venue{name, city,
  region, …}`, `organization{…}`, `registration{…}`, `description`, `image`, `public`.
- `detailuri` is the event's public MSR page with tracking parameters
  (`?utm_source=apis&utm_medium=apim&…`).
- The organization ID (35 characters, `XXXXXXXX-XXXX-XXXX-XXXXXXXXXXXXXXXX`, hex, upper case) is
  not shown to users but appears once in the HTML of the club's MSR page
  (`https://www.motorsportreg.com/orgs/<slug>`). Found: NASCC `2386B6E3-96BC-AE58-0812CF4B556BCBC2`
  (`/orgs/nascc`), WSCC `4D45EE74-0A85-F011-ACBD5982F016139D` (`/orgs/winnipeg-sports-car-club`).
  ESCC has no MSR page.
- Feeds mix race events with memberships, gift cards, HPDE, autocross, schools and banquets.
  Race events have `type` **"Ice Racing"** or **"Club Race"**. "Club Race" also covers volunteer
  sign-ups. One race weekend is often several MSR events (NASCC June 13–14: Enduro, Sprint, Time
  Attack). Cancellation shows as `cancelled` and/or a renamed event ("… – CANCELED"); a
  reschedule can be a new MSR event.
- MSR's API terms: use is "subject to prior approval", feeds may change, fetch and cache rather
  than calling live, and displayed listings carry "Powered by MotorsportReg.com" attribution.
  This feature fetches once a day and shows the data only to admins, with an attribution line.
  The feed is public; no approval request is planned (owner's decision, 2026-09-29).

## 1. Data

- **`clubs.msr_org_id`** `TEXT NOT NULL DEFAULT ''` (added by `db_init`). On `db_init`, NASCC and
  WSCC get the IDs above if theirs is blank (never overwriting an admin's value).
- **New table `msr_events`**, one row per stored MSR event:
  `msr_id` (PK, the feed `id`), `club_code`, `name`, `start_date`, `end_date`, `type`, `venue`,
  `detail_url` (tracking parameters removed), `cancelled` (0/1), `status`
  (`new` | `ignored` | `added` | `gone`), `hub_event_id` (nullable, FK-by-convention to `events.id`),
  snapshot columns `snap_name`, `snap_start`, `snap_venue`, `snap_cancelled` (the values when an
  admin last added/applied/kept it; null while `new`), `first_seen_at`, `last_seen_at`.
  Several rows may point at the same `hub_event_id` (multi-event weekends).
- **"Changed"** is derived, not stored: a row with `status = 'added'` whose current name, start,
  venue or cancelled differs from its snapshot, or whose `status = 'gone'` (added, then missing
  from a successful fetch).
- **Per-club sync status** in `settings`: `msr_sync_ok_<CODE>` (datetime of last successful fetch)
  and `msr_sync_error_<CODE>` (last error text, cleared on success).
- Only types "Ice Racing" and "Club Race" are stored. Everything else is never stored.

## 2. Daily check

- New CLI script **`msr-sync.php`** (403 over the web). **`reminders-cron.sh`** runs it after
  `reminders.php`; the two are independent (a failing reminders run does not stop the sync, and
  vice versa). Output goes to `data/msr-sync.log`.
- For each club with an `msr_org_id`: fetch the feed (15-second timeout, cancelled events
  included). On success:
  - Upsert each race-type event (update name/dates/type/venue/link/cancelled and `last_seen_at`;
    keep `status`, `hub_event_id` and snapshots).
  - A stored row for this club that is not in the feed: `new` or `ignored` → deleted; `added` →
    `gone` (never deactivates the hub event). A `gone` row that is back in the feed → `added`
    again (change detection against its snapshot resumes).
  - Save `msr_sync_ok_<CODE>`, clear the error.
- On failure (network error, timeout, non-200, unreadable JSON, no `response.events` array): change
  nothing in `msr_events`; save `msr_sync_error_<CODE>`; log it. Entries missing `id`, `name` or a
  valid `start` are skipped and counted in the log.
- The fetch is behind a small injectable function so tests never call MSR.
- **Check now** (admin POST, §3) runs the same sync synchronously.

## 3. Admin screens

- **Events tab badge:** the admin tab reads **"Events (N)"** where N = new + changed MSR events.
- **Events tab strip:** when N > 0, above the Add event button: "From MotorsportReg: 3 new ·
  1 changed" with a **Review** button → `admin.php?action=msr` (Events tab highlighted).
  When N = 0 and at least one club is connected, a quiet "Check MotorsportReg" link to the same page.
- **Review page** (`admin.php?action=msr`), same list + modal patterns as the admin tabs:
  1. **Changed** — each row names the hub event and what changed, old → new ("Date: Jan 24 →
     Feb 21", "Cancelled on MotorsportReg", "No longer on MotorsportReg").
     - **Apply to hub event**: updates only the fields that changed since the snapshot (start →
       `event_date`, venue → `location`, name → `name`; cancellation → deactivates the hub event,
       button labelled **Deactivate hub event**); then snapshot := current. Not offered for `gone`.
     - **Keep as is**: snapshot := current (for `gone`: row deleted).
  2. **New**, soonest first: dates, name, club, **Ice** / **Race** chip, **Open on MotorsportReg ↗**.
     - **Add to hub**: the Add event modal pre-filled — name, start date, venue as location,
       discipline `ice` for "Ice Racing" else `summer`, host club, MSR link. Saving creates the hub
       event (same validation as Add event) and marks the row `added` with a snapshot.
     - **Add to an existing event**: a modal with a select of active hub events; pre-selected is
       the event of the same club whose date is within 3 days of the MSR start (nearest first),
       else none. Saving marks the row `added` to that event (the hub event's own link is not
       changed).
     - **Ignore** → `ignored`.
     - A new event that is already cancelled on MSR shows a **Cancelled** chip and only **Ignore**.
  3. **Ignored** (collapsed `<details>`): each with **Restore** → `new`.
  4. **Footer:** per connected club "Last checked: <date/time>" or "Failed: <error> (last
     success <date/time>)"; **Check now** button; "Event data from MotorsportReg.com" attribution.
- Every action is a CSRF-checked POST via `adminRequirePost()`, and is guarded by the row's
  expected state (Add/Ignore need `new`; Restore needs `ignored`; Apply/Keep need a changed
  `added`/`gone` row). A stale action shows "That MotorsportReg event was already handled." and
  changes nothing. Errors reopen the modal via the admin `?edit=` pattern.
- **Clubs tab:** the club modal gains **MotorsportReg page** (`https://www.motorsportreg.com/orgs/…`
  address, or the organization ID itself). On save the hub fetches the page and extracts the one
  organization ID; if none is found: "Couldn't find a MotorsportReg organization on that page.
  Check the address, or ask the club for its MotorsportReg organization ID." Blank = not
  connected. The clubs table gains a **MotorsportReg** status (Connected / —).

## 4. Rules and safety

- Only admins see or trigger anything here.
- Feed links are stored only when they are `https://` addresses on `motorsportreg.com` (any
  subdomain); otherwise stored as ''. All feed text is escaped on output.
- The hub never changes a hub event without an admin click.
- Feed data never reaches drivers directly; drivers see only hub events.

## 5. Testing

- Unit (pure): feed parsing from saved real responses (`tests/fixtures/msr-nascc.json`,
  `msr-wscc.json`, captured 2026-09-29), type filter, link tidying (utm removal, host check),
  org ID extraction from a saved club page snippet, change detection, pre-selection of the
  existing event.
- DB: first sync, idempotent re-sync, date change, cancellation, disappearance (`new` deleted,
  `added` → `gone`), failed fetch changes nothing, snapshot handling on Apply/Keep.
- Admin actions: add, add to existing, ignore/restore, apply/keep, stale-action guard, badge count.
- Phone audit visits the review page. No test calls MSR.

## Out of scope

Telling drivers an event was cancelled; importing entry lists or classes; clubs not on MSR;
anything needing an MSR login; types other than "Ice Racing" and "Club Race".
