# Admin Submission Store — Design Spec
**Date:** 2026-08-21  
**Project:** WCMA Classing Calculator (221racing.com)  
**Status:** Approved for implementation

---

## Overview

Tech inspectors need to rapidly class cars in the field and retain a browsable record of every submitted tech sheet, including uploaded files. When a competitor disputes their class, the inspector must be able to show the exact calculation breakdown on the spot.

Submitted forms are currently emailed and immediately discarded. This feature persists them server-side (PHP + SQLite on IONOS) and adds a PIN-protected admin page for reviewing, sorting, re-emailing, and deleting submissions.

---

## Architecture

**Approach:** Extend the existing PHP backend with a shared database include and a new admin page.

**New / modified files:**

| File | Role |
|---|---|
| `db.php` | All SQLite operations — shared include |
| `car-classing.php` | Modified: persist submission + keep files |
| `admin.php` | PIN-protected admin UI |
| `data/.htaccess` | Block direct HTTP access to SQLite DB |
| `uploads/.htaccess` | Block direct HTTP access to upload directory |

**No framework, no build step** — plain PHP, consistent with the existing backend.

---

## Data Model

### `submissions` table

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `submitted_at` | DATETIME | Server time (America/Denver) |
| `name` | TEXT | |
| `email` | TEXT | |
| `year` | TEXT | |
| `make` | TEXT | |
| `model` | TEXT | |
| `comments` | TEXT | Nullable |
| `competition_weight` | INTEGER | |
| `declared_hp` | INTEGER | |
| `dyno_hp` | INTEGER | Nullable |
| `chassis_display` | TEXT | Human-readable selection |
| `body_mods_display` | TEXT | |
| `transmission_display` | TEXT | |
| `drivetrain_display` | TEXT | |
| `tires_display` | TEXT | |
| `brake_suspension` | TEXT | JSON-encoded array of selections |
| `chassis_value` | REAL | Numeric mod factor |
| `body_mods_value` | REAL | |
| `transmission_value` | REAL | |
| `drivetrain_value` | REAL | |
| `tires_value` | REAL | |
| `brake_suspension_value` | REAL | Sum of all checked brake/susp items |
| `weight_factor` | REAL | |
| `modification_factor` | REAL | Sum of all individual mod values |
| `base_ratio` | REAL | |
| `modified_ratio` | REAL | |
| `calculated_class` | TEXT | GTU / GT1 / GT2 / GT3 / GT4 / IT1 / IT2 |
| `dyno_chart_path` | TEXT | Relative path from project root, nullable |
| `dyno_table_path` | TEXT | Nullable |
| `car_image_path` | TEXT | Nullable |
| `email_sent` | INTEGER | 1 = sent, 0 = failed |

### `login_attempts` table

| Column | Type | Notes |
|---|---|---|
| `ip` | TEXT PK | Remote IP address |
| `attempts` | INTEGER | Rolling failed attempt count |
| `last_attempt_at` | DATETIME | Timestamp of most recent failure |

---

## File Storage

- Uploaded files stored at `uploads/{submission_id}/` with original sanitized filenames, relative to `car-classing.php`
- Files are **not deleted** after emailing (change from current behaviour)
- Files served through `admin.php?action=file&id=X&field=car_image|dyno_chart|dyno_table` — path resolved from DB, never from user input
- `uploads/.htaccess` blocks direct HTTP access; all file delivery goes through the PHP endpoint

**Insert order on submission:**
1. Validate all fields and files
2. Insert DB row (without file paths) → get `$id`
3. Move uploaded files into `uploads/{$id}/`
4. Update DB row with file paths
5. Send emails
6. Update `email_sent` flag

---

## Changes to `car-classing.php`

1. `require 'db.php'` added after PHPMailer requires
2. The cleanup loop that deletes uploaded files after emailing (currently lines 317–322) is removed
3. After validation passes: insert → move files → update paths → email → set `email_sent`
4. Individual modifier values posted as hidden fields from the JS (`chassis_value`, `body_mods_value`, `transmission_value`, `drivetrain_value`, `tires_value`, `brake_suspension_value`) — requires a small addition to `ui-controller.js` in the hidden-field-building block on form submit

The JSON response to the browser is unchanged — submitter experience is identical.

---

## `admin.php` — Authentication

- PIN stored as a `bcrypt` hash in a config constant at the top of `admin.php`
- `session_start()` used for auth state; session destroyed on logout
- All routes pass through `requireAuth()` — redirects to login if no valid session
- No session timeout (field use case; designed so a timeout constant can be added in one place)
- `requireAuth()` is the sole auth gate — straightforward to replace with Google OAuth later

**Rate limiting:**

Tracked in the `login_attempts` SQLite table.

- 5 failed attempts within a 15-minute window triggers lockout
- Lockout duration: 15 minutes from the last failed attempt
- On successful login: attempt record for that IP is cleared
- Lockout message shows remaining minutes; no hint about the PIN
- Window expiry checked on each attempt — no cron required

---

## `admin.php` — List View (default after login)

Sortable HTML table:

| Column | Sortable | Notes |
|---|---|---|
| Submitted | Yes | Default sort: newest first |
| Name | Yes | |
| Vehicle | Yes | Year Make Model combined |
| Weight | Yes | |
| HP | Yes | |
| Class | Yes | |
| Email | — | ✓ or ⚠ icon (failed delivery) |
| Actions | — | View · Delete |

- Sort state in URL params (`?sort=submitted_at&dir=desc`) — survives page refresh
- **Delete** is a POST form with JS confirm dialog, executed from the list view
- **View** links to detail page

---

## `admin.php` — Detail View (`?action=view&id=X`)

Two-column layout:

**Left — Calculation breakdown:**
```
Base Ratio:                  13.25
Weight Factor:               +0.00
Chassis (Sports Racer):      +0.50
Body Mods (Full aero):       +0.30
Transmission (Sequential):   +0.20
Drivetrain (AWD):            +0.10
Tires (Slick):               +0.40
Brake/Susp (Big brakes):     +0.20
─────────────────────────────────
Modified Ratio:              14.95  → IT1
```

Followed by: contact info, vehicle info, all modifier display text, comments.

**Right — Uploaded files:**
- Car image: rendered as inline thumbnail
- Dyno chart / dyno table: clickable link (images inline, PDF/DOC open in new tab)
- File slots with no upload are omitted
- Files are served via `admin.php?action=file&id=X&field=car_image` — path is looked up from DB by ID and field name, never from user input; content-type header set from file extension

**Actions at top of page:**
- **Re-email** (POST button)
- **Back to list** (link)

Delete is only available from the list view to prevent accidental deletion mid-review.

---

## `admin.php` — Re-email (`?action=resend&id=X`, POST only)

- Rebuilds the same HTML email body from stored DB data
- Re-attaches files from `uploads/{id}/` on disk
- Sends to both the tech admin email (config constant) and the submitter's stored email address
- Updates `email_sent = 1` on success
- Redirects back to detail view with a flash message (success or failure)

---

## `admin.php` — Delete (`?action=delete&id=X`, POST only)

- Validates CSRF token
- Deletes all files in `uploads/{id}/` then removes the directory
- Deletes the DB row
- Redirects to list view with a flash confirmation message

---

## Security

| Concern | Mitigation |
|---|---|
| SQLite file exposure | `data/.htaccess`: `Deny from all` |
| Direct file URL access | `uploads/.htaccess`: `Deny from all`; files served via PHP only |
| Path traversal | File paths looked up from DB by ID — never taken from user input |
| SQL injection | All DB queries via PDO prepared statements |
| XSS | All admin output via `htmlspecialchars()` |
| CSRF | Token generated per-session, validated on delete and re-email POST actions |
| PIN brute force | Rate limiting: 5 attempts / 15 min lockout per IP |
| PIN storage | bcrypt hash — never stored in plain text |
| Auth bypass | Every route passes `requireAuth()` before any output or action |

**Intentionally deferred:**
- Google OAuth (replaces `requireAuth()` — designed for this swap)
- HTTPS assumed already configured on 221racing.com

---

## Future Considerations

- **Google OAuth:** Replace the PIN + `requireAuth()` block with Google OAuth 2.0. The rest of the admin page is unaffected.
- **Per-inspector accounts:** Would require a `users` table and associating submissions with inspector IDs.
- **Search / filter:** Not in scope now; the sortable table is sufficient for event-day use.
