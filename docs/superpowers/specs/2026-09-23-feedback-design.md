# User Feedback & Bug Reports — Design Spec
**Date:** 2026-09-23
**Project:** WCMA Classing Calculator (221racing.com)
**Status:** Draft for review

---

## Overview

Users (guests and logged-in) can submit a bug report, general feedback, or an idea from a "Feedback" link in the site header. Every submission is saved to the database first (the source of truth), then pushed best-effort to the private GitHub repo `mattEGEK/wcmaclasscalc` as an issue, and an email notification is sent to a recipient configured on the existing admin Settings page.

Out of scope: screenshot/file uploads, automatic retry or cron, syncing GitHub issue status back into the admin, public display of feedback.

---

## Data Model

New table `feedback` (added in `db.php` with `CREATE TABLE IF NOT EXISTS`, matching existing patterns):

| Column | Notes |
|---|---|
| `id` | INTEGER PK AUTOINCREMENT |
| `type` | `bug` \| `feedback` \| `idea` |
| `message` | TEXT NOT NULL, max 2000 chars |
| `email` | nullable; prefilled for logged-in users |
| `user_id` | nullable |
| `page_url` | page the user was on |
| `user_agent`, `viewport` | auto-captured |
| `calc_inputs` | nullable JSON — calculator inputs, captured for bug reports made on the calculator page |
| `ip_hash` | hashed IP, used for rate limiting |
| `status` | `new` \| `triaged` \| `resolved`, default `new` |
| `github_issue_number`, `github_issue_url` | nullable, set on successful sync |
| `github_error` | nullable, set on failed sync |
| `created_at` | DATETIME NOT NULL |

---

## Submission Flow (`feedback.php`)

POST-only endpoint returning JSON, in the style of `car-classing.php`.

1. Reject if honeypot field is filled or CSRF token is invalid.
2. Rate limit per hashed IP (default 5/hour); over limit → friendly "try again later".
3. Validate and length-cap input (type in allowed set, message required, email valid if given).
4. **Insert the row.** DB failure is the only error shown to the user.
5. **GitHub sync** (best-effort): short timeout (~5s), wrapped in try/catch. Success stores issue number + URL; failure stores the error in `github_error`. The user sees success either way.
6. **Email notification** via the existing PHPMailer/SMTP setup to the configured feedback recipient. Failure is logged and never surfaces to the user.

### GitHub issue content

- Title: `[Bug|Feedback|Idea] <first 60 chars of message>`
- Body: message, reporter name and email (repo is private), page, browser/OS, viewport, calculator inputs in a code block, link to the admin entry.
- Labels: `feedback` plus `bug` / `enhancement` / `idea` by type.
- `@` mentions in user text are neutralised (zero-width space) and user text is placed in a quoted/fenced block so it cannot ping people or inject markdown links.

### Config

`config.example.php` gains `GITHUB_TOKEN` (fine-grained PAT, Issues read/write on this repo only) and `GITHUB_REPO`. If `GITHUB_TOKEN` is empty, GitHub sync is skipped and feedback is DB + email only. The token is only read server-side.

---

## Notification Email Recipient (Admin Settings)

Follows the existing recipient pattern in `admin.php` (Notification Recipients section, `settings` table via `db_get_setting`):

- New settings keys `feedback_recipient_email` and `feedback_recipient_name`.
- Defaults from new `config.example.php` constants `FEEDBACK_RECIPIENT_EMAIL` / `FEEDBACK_RECIPIENT_NAME` (both `classing@wcma.ca` / `WCMA Classing`), via `config_default()`.
- A third "Feedback" row in the existing Notification Recipients form, validated the same way (valid email and non-empty name required), added to the existing recipients validation map so it saves through the current handler.
- `feedback.php` reads the recipient with `db_get_setting()` at send time, so changes apply immediately.

---

## Frontend

- New `js/feedback.js` module and a "Feedback" link in the header on all pages.
- The link opens a modal built like `confirm-modal.js`. Fields: type, message, email (optional for guests, prefilled and linked to the account when logged in), hidden honeypot.
- On the calculator page, bug reports include the current calculator inputs (weight, HP, selected modifiers) pulled from the UI controller.
- Success/error messages via `form-feedback.js`.

---

## Admin

A new "Feedback" tab in `admin.php`, styled like the submissions list:

- Table: type, message excerpt, reporter, date, status, GitHub issue link. Rows with a failed/missing GitHub sync show a badge.
- Detail view with status change (new / triaged / resolved).
- "Retry GitHub sync" button on rows with `github_error` and no issue number, calling the same sync function as the endpoint.
- All user text escaped on render.

---

## Failure Handling Summary

| Failure | User sees | Recorded |
|---|---|---|
| DB insert fails | Error message | — |
| GitHub sync fails | Success | `github_error`, badge in admin, manual retry |
| Email send fails | Success | Logged |
| Rate limit hit | "Try again later" | — |

---

## Testing

- PHPUnit in existing style (`DbFeedbackTest.php`): insert, list, status update, rate-limit counting, storing sync results.
- GitHub call isolated in one small function taking an injectable HTTP callable; tests use a fake (no network).
- Pure function builds issue title/body (truncation, label mapping, mention neutralising) with its own tests.
- Settings: test that the feedback recipient falls back to the config default and reads a saved override.
- Modal and admin tab verified manually in a browser.
