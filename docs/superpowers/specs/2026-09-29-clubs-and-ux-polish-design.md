# Clubs List and UX Polish — Design Spec
**Date:** 2026-09-29
**Project:** WCMA Hub (221racing.com)
**Status:** Approved in conversation (2026-09-29).

Follow-ups to the mobile UX work (`2026-09-28-mobile-ux-older-users-design.md`), chosen by the user
from its deferred list, plus one new admin feature the user asked for: a clubs list.

---

## 1. Clubs list (new)

**Why:** the "Register with the club" step on a submitted tech sheet should name the host club and
link to its MotorsportReg page, for summer and ice events. Summer events have no host club today.

- New table `clubs`: `code` (primary key, 2–12 of A–Z, 0–9, `-`), `name` (1–120 chars), `msr_url`
  (blank or an `https://` address), `active` (1/0), `created_at`.
- On `db_init`, any ice club in `ICE_CLUBS` (NASCC, WSCC) missing from `clubs` is inserted with its
  label as the name and a blank link. Admin edits are never overwritten.
- New admin tab **Clubs** (between Events and Season links): list, add, edit name / link / active.
  The code is fixed once created (events refer to it). No delete; make inactive instead.
- Events keep using `events.host_club` (a club code):
  - **Ice events** must pick a club with ice rules (`ICE_CLUBS` codes), as today.
  - **Summer events** may pick any active club, or none.
  - The Add event form's club select lists active clubs; each event row gets a small
    "Host club" select + Save so existing events can be set.
- **Register step** (tech sheet "What's next", summer and ice): "The hub doesn't register you.
  Register for <event> with the <club name>." plus a **Register on MotorsportReg ↗** button
  (`target="_blank" rel="noopener"`) when the club has a link. No club → "with the host club".
  Ice falls back to the ice rules label when the club row has no name.

## 2. UX polish (from the deferred list)

1. **Tech sheet title** includes the car's year from the car record:
   "Ice tech sheet — #42 2008 Honda Civic — NASCC Ice Race #1" (no year → as today).
2. **"Both" car with no ice activity** shows no "Needs ice tech" chip. The chip shows for a car with
   an ice sheet or an ice event tag, or a car stored as `ice`.
3. **Calculator** meets the Phase B rules (44px targets, 16px text, 4.5:1 contrast, no grey
   enabled buttons): the phone audit visits `calculator.php` signed out and must pass. Legacy blue
   text/links/icons move to hub tokens.
4. **Nudge dismiss button** (guest save nudge) is at least 44px (covered by 3).
5. **Added co-driver name** messages: "Enter Driver 3's name." (the row's number); Driver 1's
   co-driver box: "Enter the co-driver's name."
6. **Signature error**: announced once (the box by Submit keeps `role="alert"`; the message above
   the pads doesn't), and it disappears when the member starts signing the pad it names.
7. **Admin user actions**: Deactivate / Reactivate are secondary (outlined) buttons; Save role /
   Save name / Save media stay primary. All sentence case, ≥ 44px.

## Testing

PHPUnit for the clubs data, validation, seeding, event club rules, register step and title;
node tests for the co-driver message; the phone audit gains the calculator page and checks for the
signature message; screenshots of the admin Clubs tab and events at 375px.

## Out of scope

Clubs on Home/Garage event cards; per-event registration links; MotorsportReg API.
