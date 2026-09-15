# User Accounts & Admin Auth — Design Spec
**Date:** 2026-09-15
**Project:** WCMA Classing Calculator (221racing.com)
**Status:** Approved for implementation

---

## Overview

Competitors currently submit tech sheets anonymously — there is no way to look back at a previously submitted calculation, image, or dyno sheet without re-emailing the tech admin. Separately, the admin panel is protected by a single shared PIN, which doesn't scale to multiple inspectors and can't be individually revoked.

This feature adds real user accounts (Google OAuth or email/password) shared between the public site and the admin panel, replacing the PIN entirely. Logged-in competitors get a "My Submissions" page holding up to ~20 of their own past submissions (soft cap, no hard block). Admin access becomes a `role` on the same user record, managed from a new in-app screen — no more config-file PIN hashes.

---

## Architecture

**Approach:** New auth layer alongside the existing action-router files, rather than folding auth into `car-classing.php` or `admin.php` directly — both are already large and single-purpose.

**New / modified files:**

| File | Role |
|---|---|
| `db.php` | Modified: adds `users`, `password_resets` tables; `submissions.user_id` column |
| `auth.php` | New: login, register, logout, Google OAuth, password reset — action-router like `admin.php` |
| `account.php` | New: "My Submissions" list/view/delete for logged-in regular users |
| `session-status.php` | New: tiny JSON endpoint reporting login state, for the static calculator page |
| `admin.php` | Modified: PIN auth removed; `requireAuth()` checks shared session + `role = admin`; new Manage Users screen |
| `car-classing.php` | Modified: tags submission with `user_id` when the submitter is logged in |
| `car-classing.html` | Modified: small inline script swaps nav between "Sign in / Register" and "My Submissions / Logout" |
| `phpunit.phar` | New: vendored standalone PHPUnit, same manual-vendoring convention as `phpmailer/` |
| `tests/` | New: unit tests for `db.php` and pure auth logic |

No framework, no build step, no Composer — consistent with the existing backend. Google OAuth is implemented with plain cURL calls to Google's endpoints rather than an SDK.

---

## Data Model

### `users` table

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `email` | TEXT UNIQUE | |
| `password_hash` | TEXT | Nullable — NULL for Google-only accounts |
| `google_id` | TEXT UNIQUE | Nullable — NULL for password-only accounts |
| `name` | TEXT | |
| `role` | TEXT | `user` or `admin`, default `user` |
| `created_at` | DATETIME | |

An account may have `password_hash`, `google_id`, or both (linked), never neither.

**Bootstrap admin:** the user-creation function sets `role = admin` if the email being created equals `BOOTSTRAP_ADMIN_EMAIL` (`matt.sinfield@gmail.com`), regardless of which login method created it. This only applies at creation time — later role changes go through the Manage Users screen.

### `password_resets` table

| Column | Type | Notes |
|---|---|---|
| `token_hash` | TEXT PK | SHA-256 of the reset token — token itself is never stored |
| `user_id` | INTEGER | |
| `expires_at` | DATETIME | 1 hour from creation |

### `submissions` table (modified)

- New column: `user_id INTEGER` — nullable, NULL for anonymous submissions. Added via a guarded `ALTER TABLE` in `db_init()` so it's safe against an existing production DB.

### `login_attempts` table (repurposed)

Unchanged schema, but now tracks general login rate-limiting (`auth.php`) instead of admin PIN attempts — same 5-attempts/15-minute lockout logic, keyed by IP.

---

## `auth.php` — Login, Registration, OAuth, Password Reset

Action-router style, matching `admin.php`. Shared session across the whole site: `$_SESSION['user_id']`, `$_SESSION['user_role']`.

- **`?action=login`** — email/password form + "Sign in with Google" button. Rate-limited via `login_attempts`. On success: `session_regenerate_id(true)`, session populated.
- **`?action=register`** — name, email, password, confirm-password. `password_hash()` (PASSWORD_BCRYPT, same as the retired admin PIN). No email verification — account usable immediately.
- **`?action=google-login`** — redirects to Google's OAuth 2.0 authorization endpoint (`client_id`, `redirect_uri`, `scope=openid email profile`, a random `state` stashed in session for CSRF protection).
- **`?action=google-callback`** — validates `state`, exchanges `code` for a token via server-side cURL POST to `https://oauth2.googleapis.com/token`, fetches profile from `https://www.googleapis.com/oauth2/v3/userinfo`. Finds-or-creates the local user: match by `google_id` first, then by `email` (linking an existing password account to Google). Logs in the same way as password login.
- **`?action=forgot-password`** — takes an email; if it belongs to a user with a `password_hash`, emails a reset link via the existing PHPMailer/IONOS setup (token stored as its SHA-256 hash, 1-hour expiry). Always shows the same "check your email" message whether or not the email matched, to avoid leaking registered emails.
- **`?action=reset-password`** — token + new password form. Validates token existence/expiry, updates `password_hash`, deletes the token row, logs the user in.
- **`?action=logout`** — clears and destroys the session, same pattern as the current admin logout.

Google credentials (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`) are config constants at the top of `auth.php`, placeholder-and-replace-on-server, matching `ADMIN_PIN_HASH`/SMTP constants today. Comments document the exact Google Cloud Console setup steps (create project, configure OAuth consent screen, add authorized redirect URI).

---

## `account.php` — "My Submissions"

Same list/view pattern as `admin.php`, scoped to the logged-in user's own rows:

- **`?action=list`** (default) — table of the user's submissions (date, vehicle, class, email status), newest first. If the user has more than 20 saved submissions, a banner reads "You have {n} saved submissions — consider deleting some older ones." No hard block on new submissions past 20.
- **`?action=view&id=`** — same calculation-breakdown + files layout as the admin detail view, ownership-checked (`submissions.user_id` must equal `$_SESSION['user_id']`, else 404). Actions limited to "Resend confirmation to my email" and "Delete" — no tech-admin re-email.
- **`?action=delete`** — CSRF-protected, ownership-checked; deletes the row and its uploaded files, same as the admin delete logic.
- Requires login only (any role); `requireAuth()` redirects to `auth.php?action=login` if not logged in.

---

## `admin.php` Changes

- `requireAuth()` now checks `$_SESSION['user_id']` **and** `$_SESSION['user_role'] === 'admin'`. If logged in but not an admin, redirect to the calculator with a "not authorized" flash instead of to a login form.
- `ADMIN_PIN_HASH`, `handleLogin()`, and the PIN login form are deleted. `admin.php?action=login` simply redirects to `auth.php?action=login`.
- New **`?action=users`** screen: table of all users (email, name, role, created_at, login method — password/Google/both) with a promote/demote button per row (POST + CSRF). A count check prevents demoting the last remaining admin.
- List/detail/resend/delete views for submissions are unchanged in behavior.

---

## Public Page Integration

- `session-status.php` returns JSON: `{"loggedIn": true, "name": "...", "role": "user"}` or `{"loggedIn": false}`, reading the shared session.
- `car-classing.html` adds a small inline script that fetches this on load and swaps a nav placeholder between "Sign in / Register" links and "My Submissions ({name}) / Logout" links. The page's static-file architecture (documented in `CLAUDE.md`) is unchanged — no PHP is embedded in it.
- `car-classing.php` calls `session_start()`; if `$_SESSION['user_id']` is set, it's passed into `db_insert_submission()` as `user_id`. Anonymous submitters continue to get `NULL`, unaffected.

---

## Security

| Concern | Mitigation |
|---|---|
| Password storage | `password_hash()` (bcrypt) — never stored in plain text |
| Reset token storage | Only the SHA-256 hash is stored; tokens are single-use and expire in 1 hour |
| OAuth CSRF | `state` parameter validated against the session on callback |
| Login brute force | Rate limiting via `login_attempts`: 5 attempts / 15 min lockout per IP |
| Email enumeration | "Forgot password" always responds identically regardless of match |
| CSRF (state-changing actions) | Existing per-session token pattern, validated on all delete/promote/resend POSTs |
| SQL injection | All queries via PDO prepared statements |
| XSS | All output via `htmlspecialchars()` |
| IDOR on submissions | `account.php` ownership check: `user_id` must match session user |
| Admin lockout self-inflicted | Manage Users screen blocks demoting the sole remaining admin |
| Session fixation | `session_regenerate_id(true)` on every successful login (password or OAuth) |
| Cookie hardening | `session_set_cookie_params()` sets `HttpOnly`, `Secure` (when HTTPS), `SameSite=Lax` in a shared bootstrap, added while touching session handling anyway |

---

## Testing

No automated test suite exists in this repo today. This feature adds a scoped one rather than deferring entirely:

- **Tooling:** `phpunit.phar` (standalone PHPUnit build) vendored under the project root, same manual-vendoring convention as `phpmailer/src/`. Run via `php phpunit.phar tests/`.
- **In scope:** unit tests for `db.php`'s new functions (`db_create_user`, user lookup by email/google_id, bootstrap-admin role assignment, password-reset token creation/validation/expiry) run against a temporary SQLite file per test. Also pure-logic tests for anything factored out of `auth.php` that doesn't require a live HTTP request (e.g. token expiry checks, the "last remaining admin" guard).
- **Out of scope (manual verification only):** the request-handling layer (`handleLogin`, OAuth redirect/callback, form rendering) calls `header()`/`exit` directly and isn't practical to unit test without refactoring the router pattern. These are verified manually:
  - Register via email/password, log out, log back in.
  - Google OAuth round trip (requires a Google Cloud Console client, created outside this design).
  - Submit the calculator logged in → appears in "My Submissions"; submit logged out → doesn't appear anywhere, still emails/saves anonymously.
  - Fill "My Submissions" to 21 entries → over-limit banner appears, submission still succeeds.
  - Promote/demote a user from Manage Users; confirm a demoted admin is locked out of `admin.php`.
  - Confirm `matt.sinfield@gmail.com` gets `role = admin` automatically on first account creation via either login method.
  - Full end-to-end HTTP-level automated testing (spinning up PHP's built-in server and driving it with real requests/cookies) is noted as a future follow-up, not built here.

---

## Future Considerations

- Email verification for password registrations (currently skipped per product decision).
- Hard cap / auto-eviction on saved submissions if 20 proves too permissive in practice.
- Full HTTP-level integration test suite (PHP built-in server + real requests) once the manual-verification burden grows.
- User self-service account deletion / data export.
