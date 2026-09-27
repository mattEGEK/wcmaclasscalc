# WCMA Hub — Backlog

Ideas parked for future review. Nothing here is approved or scheduled. Each item needs its own
brainstorm and spec before any work starts.

Captured 2026-09-27 from a review of wcma.ca and WCMA's MotorsportReg (MSR) org page. Some
wcma.ca pages (Race Results, Track Records, calendars, Racing Forms) could not be read because the
site rate-limited us, so items that depend on them are unverified.

---

## MotorsportReg API integration (preferred direction)

The main objection to the driver-entered ideas further down is that drivers won't type things in.
Pulling data from MSR avoids that. The current hub spec
(`specs/2026-09-24-wcma-hub-design.md`) rules out an MSR API integration, so doing this is a
scope change.

**What lives where**

| Data | Where | Access | What the hub gets |
|---|---|---|---|
| Licence holders per season | WCMA org (one MSR product per licence type) | Admin credentials | Driver licence type and status, no input needed |
| Car classing and number reservations | WCMA org, "Car Classing & Number Reservation" | Admin credentials | Number registry, clashes, reserved cars with no hub declaration |
| Club event calendars | Each club's org | Public, no auth (`/rest/calendars/organization/{id}`, also `.ics`/`.rss`) | Event list fills itself, no admin typing |
| Entries per event (car, class, number) | Each club's org | Each club's admin credentials (the public `/entrylist` may be hidden or empty) | Events tagged for drivers automatically |

**API facts (from api.motorsportreg.com, 2026-09-27)**
- Auth: OAuth 1.0a (preferred, app must be registered with MSR), or an org admin's username and
  password over HTTP Basic plus an `X-Organization-Id` header (MSR says this is fine for in-house
  scripts). Use of the API needs MSR's prior approval and can be revoked.
- Useful endpoints: `/rest/events/{id}/attendees` (supports `?fields=questions` and
  `lastupdate_since`), `/rest/events/{id}/assignments` (entries, supports
  `?fields=profile,vehicle_questions`), `/rest/events/{id}/segments`, `/rest/members`,
  `/rest/members/{id}/logbook`.
- No webhooks, so it means a polling cron job, like the reminder run.
- Not exposed: licences as such (only as registrations for the licence products), and uploaded
  documents.
- Response field lists aren't documented, so make one live call before designing anything.
- Anything displayed needs the "Powered by MotorsportReg.com" attribution.

**Suggested order**
1. Import club calendars from the public feeds. No approval needed.
2. Test with a WCMA MSR admin login: pull the licence and classing registrations and see what
   fields come back.
3. Club entry lists, so events get tagged automatically (needs every club to agree).

**Open questions:** Who holds WCMA's MSR admin login? Which member clubs would share access? How
should unmatched emails between hub accounts and MSR profiles be handled?

---

## Driver-entered features (parked: drivers are unlikely to fill these in)

Consider these again only if the MSR data above can fill them in automatically.

1. **Licence renewal checklist.** Licence type, date of birth (for the medical form required at
   60+) and a renewal date, with reminders before the $35 renewal late fee applies.
2. **Automatic driver log book.** One entry per tech sheet (date, event, car, class), exportable as
   a PDF. Licence renewals ask for a log book or results.
3. **GT classification form PDF.** MSR's classing product asks GT cars to upload a classification
   form. The hub could produce it filled in from the car's accepted declaration.
4. **Car number lookup.** A warning when a number is already recorded on another active car.
   Advisory only.
5. **Re-declare after a rule change.** Tag each calculator rules set with a season or version, flag
   declarations made under an older one, and send reminders.
6. **Hub events as a calendar feed.** An iCal or embeddable list that wcma.ca's Road Race Calendar
   could use (overlaps with MSR step 1).
7. **Worker and official sign-up and hours.** A per-event sign-up that feeds the Travel Fund, the
   SKIP program and the workers' luncheon list.
8. **Season rollover wizard.** New MSR season links, gear season reset and event archive in one
   admin step. Check what `admin-season-links.php` already covers first.
9. **Results and track records.** Import from timing software exports. The spec currently excludes
   this, and the format hasn't been checked.

---

## For the wcma.ca webmaster (not hub work)

- The volunteer chair email on the home page is empty ("contact our volunteer chair < >").
- The Contacts link points to an old hosting domain, `negz0684.mywhc.ca`.
