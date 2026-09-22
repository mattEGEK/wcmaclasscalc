# Competitor Flow Clarity — Design Spec
**Date:** 2026-09-22
**Project:** WCMA Classing Calculator (221racing.com)
**Status:** Draft — pending user review

---

## Overview

Class declaration and tech sheets have each shipped as their own feature (see `2026-09-15-unified-my-cars-design.md` and `2026-09-22-tech-sheets-design.md`), but nothing in the product ties them together as the two things every competitor must do. The target audience skews older and less comfortable with web forms in general, which sharpens the problem: they need the relationship between the two steps spelled out in plain language, not left to be inferred from page structure.

**The relationship, stated once and for all:** class declaration happens once a season, or again whenever the car changes. A tech sheet is required once per event, regardless of whether the car has changed. These are independent cadences — nothing here proposes merging the two workflows.

This spec covers the presentation layer only: landing-page structure, copy/terminology, the My Cars list layout, and a sitewide typography/design-language baseline. It does not change the `car-classing.php`/`tech-sheets.php` data model, the calculation engine, or the admin/tech-inspector flow from the prior specs.

**Non-goal, explicitly protected:** the calculator's real-time recalculation (every input/modifier change immediately updates the displayed ratio and class, per `ui-controller.js`'s existing recalculation wiring) is not touched by anything in this spec. Any restructuring of the landing page must preserve it exactly as-is.

---

## 1. Landing page (`car-classing.html`) restructure

### 1a. Lead with the two-step overview, not the formula

Today the page opens with an "How Classing Works" box explaining Base Ratio / Weight Factor / Modified Ratio *before* the user has any orientation to why they're here. Replace the box's position and lead content:

- **New first element on the page**, above the existing instructions box: a short, plain-language panel:
  > **Every competitor needs to do two things:**
  > **1. Declare your class** — once a season, or whenever your car changes. *(You're on this step now.)*
  > **2. Submit a tech sheet** — every event, no matter what. *(You'll do this after your class is calculated and saved.)*
- The existing "How Classing Works" formula explanation stays, unchanged in content, but demoted to a collapsed/secondary position below this panel (or behind a plain "How is my class calculated?" disclosure) — it's reference material for people who want the math, not the entry point.

### 1b. Break the single dense form into clearly separated, generously-spaced sections

No JS wizard, no step-gating — same single-page form, same real-time recalculation — but each existing `<section class="form-section compact-section">` (Contact Information, Vehicle Factors, modifiers) gets:
- More vertical spacing and a visible section number ("Step 1 of 3: Contact Info", etc., purely presentational — all sections remain on one page and editable in any order)
- The `compact-section`/`compact-grid` CSS classes retired in favor of a roomier default (see §3, typography baseline)

### 1c. Replace icon-only tooltips with visible help text

The `.info-tooltip-trigger` `?` buttons (currently 0.7rem icon-only, click-to-reveal) are replaced with a plain-language line of helper text displayed directly under the relevant label (e.g. under "Competition Wgt (lbs)"), always visible — no click/hover interaction required to discover it. This removes the tooltip JS trigger entirely for the fields that currently use it; the underlying explanatory copy is preserved, just always-shown instead of hidden behind an icon.

### 1d. Real-time results stay exactly where they are

No change to `input-with-result` / `inline-result` elements or the recalculation event wiring in `ui-controller.js`. The competitor should still see their ratio and class update live as they change weight, HP, or any modifier dropdown — this is explicitly the thing not to regress.

---

## 2. Terminology

Standardize on two competitor-facing nouns everywhere: **"Class Declaration"** and **"Tech Sheet."** Concretely:

| Current copy | New copy |
|---|---|
| "My Cars" page header/nav | Kept as "My Cars" (already the unified page identity from the prior spec) — but row labels below change |
| "Submission #4" (page titles, detail view) | "Class Declaration — [Year Make Model]" |
| "My Submissions" (any remaining references) | "My Class Declarations" |
| "Submit Tech Sheet" action link | Unchanged — already correct |
| Internal-sounding terms surfaced to users ("submission", "draft" used as visible labels) | "Class Declaration" / "Draft" stays as "Draft" (already plain) |

This is a copy-only pass across `car-classing.html`, `account.php`, `tech-sheets.php`, and email templates (`car-classing.php`, `tech-sheets.php` mailer bodies) — no route or field renaming.

---

## 3. Sitewide typography / design-language baseline

Applies to `css/calculator.css` globally, not just the landing page, so My Cars and tech sheets inherit it too:

- Raise base body font size from the current 0.78–0.95rem range to a 1rem (16px) minimum for labels, inputs, and body copy; headings scale up proportionally.
- Increase line-height and vertical spacing between form groups (retiring `compact-grid`'s tight spacing as noted in §1b).
- Increase minimum interactive target size (buttons, checkboxes, the OK/N/A tech-sheet toggles) to a comfortable tap size (~44px), consistent with the tech-sheet checklist's existing "large touch targets" precedent from the prior spec — extended here to the class-declaration form and account-page actions, which don't currently follow that pattern.
- No color-token changes proposed (existing `--primary-color`/`--secondary-color` contrast is not flagged as a problem) — scope stays to size/spacing.

---

## 4. My Cars page: group by car, surface tech sheet status inline

The prior spec's list view interleaves drafts and submissions as flat rows, with tech sheets in a separate table/tab entirely (`2026-09-15-unified-my-cars-design.md` §"Future Considerations" explicitly deferred grouping by car). This spec now does that grouping, specifically to make the class-declaration → tech-sheet relationship visible without the user needing to know it exists:

- Each **submission** (classed car) becomes one card/row showing: vehicle, calculated class, declared date — and directly beneath it, in plain sentences, one line per **active event** the competitor hasn't yet submitted a tech sheet for for that car, plus one line per tech sheet already submitted (with status):
  > *2019 Mazda Miata — Class GT3 — declared Aug 3, 2026*
  > &nbsp;&nbsp;Tech sheet for **Fall Sprint (Oct 4)**: not submitted — [Submit now]
  > &nbsp;&nbsp;Tech sheet for **Summer Enduro (Jul 12)**: submitted, reviewed ✓ — [View]
- **Drafts** (unclassed, no tech-sheet relevance) keep their own simple list below, unchanged from the prior spec.
- This replaces the separate "My Tech Sheets" tab from the tech-sheets spec — tech sheets no longer live in a second place the user has to think to check; they're nested under the car they belong to. (If a competitor has tech sheets whose parent submission was deleted — shouldn't happen given the FK, but worth a defensive fallback — an "Other Tech Sheets" section preserves discoverability.)
- No new tables/columns: this is a query/rendering change in `account.php`'s `handleAccountList()`, joining each submission against `active` events and the competitor's existing tech sheets for that submission.
- Deliberately **not** a collapsible/accordion widget (per the design-language goals in this spec) — everything renders flat and always-visible, since disclosure widgets are a known friction point for less tech-comfortable users.

---

## 5. Anonymous → account nudge copy

The mechanism already exists per the prior spec (post-submission nudge linking to prefilled registration). This spec only tightens the copy to explicitly name the reason in plain terms, tying back to §1a's two-step framing:

> "Create a free account so this class declaration is saved — you'll need it when you submit a tech sheet for an upcoming event."

No mechanism change (still unverified email-match linking, per the accepted risk already documented in the prior spec).

---

## Testing

- Manual, cross-checked against the "before" screenshots of `car-classing.html` and `account.php`:
  - Confirm real-time class/ratio recalculation still fires on every weight/HP/modifier change after the landing-page restructure — this is the one thing that must not regress.
  - Confirm the two-step panel renders above the formula explanation and reads correctly at mobile width.
  - Confirm tooltip-trigger removal doesn't leave orphaned JS listeners or broken layout where `.info-tooltip-trigger` elements are removed.
  - Confirm My Cars renders one card per submission with correct nested tech-sheet-per-event status for: a car with no active-event tech sheets yet, a car with one submitted-not-reviewed sheet, a car with one reviewed sheet, and a car with sheets against multiple events.
  - Confirm terminology changes don't break any hardcoded string matching in JS (search for "Submission #" / "My Submissions" usages before renaming).
- No new PHPUnit coverage needed — no new data-layer functions beyond the join/query change in `handleAccountList()`, which existing manual test patterns from the prior specs already cover.

---

## Open Questions for Review

1. Confirm removing the standalone "My Tech Sheets" tab (folding it into per-car grouping) doesn't conflict with a use case the admin/tech-inspector side depends on — the admin Tech Sheets list is untouched either way, this only affects the competitor-facing account.php view.
2. Confirm the plain-language two-step panel copy above is acceptable as final, or wants club-specific wording review.
