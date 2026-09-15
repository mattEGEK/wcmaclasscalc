# Unified "My Cars" — Design Spec
**Date:** 2026-09-15
**Project:** WCMA Classing Calculator (221racing.com)
**Status:** Approved for implementation

---

## Overview

The calculator today has two separate, confusingly-overlapping "save" concepts: a client-side `localStorage` "saved configurations" feature (works without an account, holds raw form data, capped at 10) and the server-side "My Submissions" page from the user-accounts feature (requires login, holds actual submitted/emailed tech sheets, capped at 20). Users reasonably expect these to be the same thing.

This feature retires the client-side saved-configs mechanism and replaces it with a server-side **drafts** concept, owned by logged-in accounts, shown alongside submission history on one renamed page: **"My Cars."** Anonymous submission (no account required) continues to work exactly as it does today, gains a post-submission account-creation nudge, and registering an account after submitting anonymously automatically links past matching submissions into the new account.

---

## Data Model

### New `drafts` table

```sql
CREATE TABLE drafts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    label       TEXT,              -- e.g. "2020 Mazda MX-5", auto-derived same as today's config naming
    form_data   TEXT NOT NULL,     -- JSON blob of raw form fields, same shape as today's localStorage entry
    updated_at  DATETIME NOT NULL
);
```

A draft is kept deliberately separate from `submissions` rather than folded into that table via a status column. `submissions` has strict NOT NULL constraints (competition_weight, declared_hp, etc.) built around being an immutable tech-inspection record; a draft is incomplete by definition and doesn't need calculated results, files, or email tracking. Two tables, one page.

### `submissions` table — unchanged schema, one new behavior

No schema changes. The only new behavior: submitting from a loaded draft does **not** delete or modify the draft — it stays as the user's editable working copy, and each submit creates a fresh `submissions` row. Re-classing the same car after modifications (e.g., new brakes mid-season) preserves every past result rather than overwriting it, matching the tech inspector's need for an audit trail.

### "My Cars" cap

Drafts and submissions share one combined soft cap of 20 (same over-limit banner pattern already built for submissions-only — no hard block, just a "consider deleting some" notice once the combined count exceeds 20).

---

## Draft Save / Load Flow

Today "Save Configuration" / "Load Configuration" are pure client-side JS in `ui-controller.js`, writing to `localStorage` — no server involved. Since drafts now belong to an account, this needs new endpoints on the existing `wcma-calculator/account.php` action-router:

- **`?action=draft-save`** (POST, called via `fetch()` from `car-classing.html`) — accepts the same form-field JSON `getAllFormDataForSave()` already builds client-side. Requires login (`requireLogin()`, same guard as every other `account.php` action). Upserts a `drafts` row for the current user (update if a draft with that label already exists for this user, otherwise insert — avoiding unbounded duplicate drafts for the same car when a user hits "Save" repeatedly while iterating).
- **`?action=draft-load&id=`** (GET, `fetch()`-called) — returns one draft's `form_data` as JSON. Ownership-scoped exactly like `db_get_user_submission()` (`WHERE id = :id AND user_id = :user_id`).
- **`?action=draft-list`** (GET, `fetch()`-called) — returns the current user's drafts as JSON, for populating the existing "Load Configuration" modal without a full page load.
- **`?action=draft-delete`** (POST, CSRF-protected like every other state-changing `account.php` action) — deletes one draft, ownership-scoped.

### Calculator page changes

`car-classing.html`'s "Save Configuration" button is relabeled **"Save to My Cars"**. Clicking it while logged out redirects to `auth.php?action=login` (same pattern as every other login-gated action in this feature) rather than silently failing or falling back to `localStorage`. The existing "Load Configuration" modal UI is unchanged visually, but its data source moves from `localStorage.getItem('wcma-saved-configs')` to a `fetch('account.php?action=draft-list')` call.

---

## Anonymous Submission Incentive

Anonymous submission (no login) is unchanged — it still writes a `submissions` row with `user_id = NULL`, per the existing user-accounts design. After a successful anonymous submission, the success message in `form-handler.js` gains a second line nudging account creation: *"Want to track this car's history? Create a free account"* — linking to `auth.php?action=register&email=<the submitted email>`, with the registration form prefilling that email field, to reduce friction at the exact moment the value proposition (history tracking) is obvious.

---

## Account-Linking on Registration

In `handleRegister()` (`wcma-calculator/auth.php`), immediately after `db_create_user()` succeeds, run one linking step: find every row in `submissions` where `user_id IS NULL` and `email` matches the new account's email (case-insensitive), and set `user_id` to the newly created account's id.

This is **automatic and unverified** — no email-confirmation gate. This was an explicit, deliberate risk acceptance: since account registration doesn't verify email ownership, someone who knows another person's email could theoretically register with it first and see that person's past anonymous submissions (car photos, dyno data, weight/HP). Accepted as low-stakes for a club tool where this data isn't highly sensitive, rather than adding email-verification friction to every registration for this one edge case.

If any submissions were linked, the post-registration flash message says so explicitly (e.g., *"Welcome — we found 3 past submissions under this email and added them to My Cars"*) so the behavior is visible to the user rather than a silent background action.

New DB function: `db_link_submissions_by_email(PDO $pdo, int $user_id, string $email): int` (returns count linked, for the flash message).

---

## Removing the Old Feature

Delete entirely from `wcma-calculator/js/ui-controller.js`: `saveConfiguration()`, `getSavedConfigurations()`, `loadConfiguration()`, `deleteConfiguration()`, `showLoadModal()`, `closeLoadModal()`, and all reads/writes of the `wcma-saved-configs` `localStorage` key. The "Save Configuration" / "Load Configuration" buttons' click handlers are rewired to the new server-backed flow described above instead. No migration path for existing browser-local drafts — they were always ephemeral and never tied to an account.

---

## `account.php` → "My Cars" Rename

The page's title, nav link text, and header ("My Submissions" → "My Cars") are updated everywhere they appear (`session-status.php`-driven nav on `car-classing.html`, the page's own `<title>`/`<h1>`, links elsewhere pointing at `account.php`). The URL/filename (`account.php`) stays the same — only user-facing copy changes. The list view (`?action=list`) now interleaves drafts and submissions sorted by most-recent activity (a draft's `updated_at`, a submission's `submitted_at`), each row showing its type and the appropriate action set:

| Row type | Actions |
|---|---|
| Draft | Edit (loads into calculator) · Delete |
| Submission | View · Resend · Delete |

---

## Security

| Concern | Mitigation |
|---|---|
| Draft ownership (IDOR) | Every draft query scoped `WHERE user_id = :user_id`, same pattern as `db_get_user_submission()` |
| CSRF on draft-save/delete | Existing per-session token pattern, validated on both |
| Account-linking email spoofing | **Accepted risk** (see above) — no verification gate, deliberate trade-off for this app's threat model |
| Draft data exposure | `form_data` JSON may contain the same PII as a submission (name, email, vehicle) — same ownership scoping as submissions, no new exposure surface |

---

## Testing

- Unit tests (PHPUnit, matching the existing `tests/` pattern): `db_create_draft`/`db_update_draft`/`db_get_user_drafts`/`db_get_user_draft`/`db_delete_draft`, and `db_link_submissions_by_email` (matching by email case-insensitively, only linking `user_id IS NULL` rows, returning the correct count).
- Manual: save a draft while logged in → confirm it appears in "My Cars"; load it back into the calculator → confirm fields repopulate; submit from a loaded draft → confirm the draft still exists AND a new submission appears; submit anonymously → confirm the account-creation nudge appears with the email prefilled in the registration link; register with an email matching a past anonymous submission → confirm it gets linked and the flash message reports the count.

---

## Future Considerations

- Grouping submissions by car (Approach C from brainstorming, deferred): rather than a flat list, group multiple submissions for the same car under one expandable entry. Not built now — flat list with type badges is simpler and sufficient.
- Draft auto-save (save-as-you-type) instead of an explicit button — not requested, deferred.
