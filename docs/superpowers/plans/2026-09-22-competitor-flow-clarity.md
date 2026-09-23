# Competitor Flow Clarity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the relationship between class declaration (once a season, or when the car changes) and tech sheets (every event, regardless) legible to an older, less tech-fluent driver — starting from the landing page, through consistent terminology, a readable typography baseline, and a My Cars page that nests tech-sheet status under the car it belongs to.

**Architecture:** Presentation-layer changes only across the existing `car-classing.html` / `account.php` / `css/calculator.css` / `js/ui-controller.js` / `js/form-handler.js` files. One new pure PHP data-transformation function (`buildCarTechSheetGroups()`) is added to `view_helpers.php` so the My Cars grouping logic is unit-testable independent of HTML rendering — everything else is copy, markup, and CSS.

**Tech Stack:** Vanilla PHP (no framework), vanilla ES6 JS modules, hand-written CSS, PHPUnit 10 for the one new testable unit.

**Spec:** `docs/superpowers/specs/2026-09-22-competitor-flow-clarity-design.md`

## Global Constraints

- Real-time class/ratio recalculation on every weight/HP/modifier change (`ui-controller.js`'s existing `handleCalculationUpdate()` wiring) must not be touched or regressed by any task in this plan.
- Competitor-facing copy uses exactly two nouns: **"Class Declaration"** and **"Tech Sheet"** — never "submission" or "My Submissions" in user-visible text.
- No new database tables or columns. No changes to `car-classing.php`'s or `tech-sheets.php`'s calculation/persistence logic.
- Body/label/input font-size floor: `1rem` (16px) for primary reading text; secondary/meta text floor: `0.95rem`.
- No JS-driven accordions/collapsible widgets are introduced for the My Cars restructure — everything renders flat and always-visible. The one exception, explicitly sanctioned by the spec, is a native `<details>` disclosure for the de-emphasized formula explanation on the landing page.
- `admin.php` is out of scope — its terminology and layout are untouched by this plan.

---

### Task 1: Landing page — lead with the two-step overview, demote the formula explanation

**Files:**
- Modify: `wcma-calculator/car-classing.html:12-32`
- Modify: `wcma-calculator/css/calculator.css` (append new rules near the existing `.instructions-box` block, after line 168)

**Interfaces:**
- Produces: `.two-step-panel` markup block (no JS hooks — pure static HTML/CSS), and wraps the existing "How Classing Works" content in `<details class="calc-explainer-details">`. No other task depends on this one.

- [ ] **Step 1: Replace the header→instructions-box region in `car-classing.html`**

Current content at lines 12-32 (inside `<div class="container">`, right after `</header>`):

```html
        <div class="instructions-box">
            <h2>How Classing Works</h2>
            <ol class="how-it-works-steps">
                <li><strong>Base Ratio.</strong> Competition Weight &divide; Declared HP gives your starting ratio and Base Class (e.g. a 2800&nbsp;lb / 350&nbsp;hp car &rarr; ratio 8.00 &rarr; GT2).</li>
                <li><strong>Modifiers shift the ratio.</strong> Each chassis, body, transmission, drivetrain, tire, and brake/suspension option you select adds or subtracts from that ratio &mdash; the value next to each dropdown shows exactly how much.</li>
                <li><strong>Final class.</strong> Base Ratio + Weight Factor + Modification Factor = Modified Ratio, and the Modified Ratio determines your Calculated Class shown on the right.</li>
            </ol>
            <p class="rulebook-disclaimer">Per the Sporting Regulations, this Calculator &ldquo;substitutes for the class rules and vehicle specification sheet&rdquo; &mdash; what you submit is your class declaration to WCMA, and it needs to be <strong>kept up to date and accurate at all times</strong>. See the official <a href="https://www.wcma.ca/racing/racing-regulations/" target="_blank" rel="noopener">Sporting &amp; Technical Regulations</a> (updated annually) for the full criteria.</p>
        </div>
```

Replace it with:

```html
        <div class="two-step-panel">
            <p class="two-step-panel-title">Every competitor needs to do two things:</p>
            <ol class="two-step-list">
                <li><strong>Declare your class</strong> &mdash; once a season, or whenever your car changes. <span class="two-step-current">(You're on this step now.)</span></li>
                <li><strong>Submit a tech sheet</strong> &mdash; every event, no matter what. <span class="two-step-note">(You'll do this after your class is calculated and saved.)</span></li>
            </ol>
        </div>

        <details class="calc-explainer-details">
            <summary>How is my class calculated?</summary>
            <div class="instructions-box">
                <ol class="how-it-works-steps">
                    <li><strong>Base Ratio.</strong> Competition Weight &divide; Declared HP gives your starting ratio and Base Class (e.g. a 2800&nbsp;lb / 350&nbsp;hp car &rarr; ratio 8.00 &rarr; GT2).</li>
                    <li><strong>Modifiers shift the ratio.</strong> Each chassis, body, transmission, drivetrain, tire, and brake/suspension option you select adds or subtracts from that ratio &mdash; the value next to each dropdown shows exactly how much.</li>
                    <li><strong>Final class.</strong> Base Ratio + Weight Factor + Modification Factor = Modified Ratio, and the Modified Ratio determines your Calculated Class shown on the right.</li>
                </ol>
                <p class="rulebook-disclaimer">Per the Sporting Regulations, this Calculator &ldquo;substitutes for the class rules and vehicle specification sheet&rdquo; &mdash; what you submit is your class declaration to WCMA, and it needs to be <strong>kept up to date and accurate at all times</strong>. See the official <a href="https://www.wcma.ca/racing/racing-regulations/" target="_blank" rel="noopener">Sporting &amp; Technical Regulations</a> (updated annually) for the full criteria.</p>
            </div>
        </details>
```

- [ ] **Step 2: Add the panel/disclosure CSS to `calculator.css`**

Insert immediately after the existing `.rulebook-disclaimer a { ... }` rule (after line 168, before the `/* Info tooltip trigger + popover */` comment):

```css
/* ── Two-step overview panel (landing page) ─────────────────────────────────── */
.two-step-panel {
    background: #eaf6ff;
    border: 1px solid var(--secondary-color);
    border-radius: var(--border-radius);
    padding: calc(var(--spacing-unit) * 1) calc(var(--spacing-unit) * 1.25);
    margin-bottom: var(--spacing-unit);
}

.two-step-panel-title {
    font-weight: 700;
    font-size: 1.05rem;
    color: var(--primary-color);
    margin: 0 0 calc(var(--spacing-unit) * 0.5) 0;
}

.two-step-list {
    margin: 0 0 0 calc(var(--spacing-unit) * 1.2);
    padding: 0;
    font-size: 1rem;
    line-height: 1.6;
    color: var(--text-color);
}

.two-step-list li {
    margin-bottom: calc(var(--spacing-unit) * 0.4);
}

.two-step-list li:last-child {
    margin-bottom: 0;
}

.two-step-current,
.two-step-note {
    color: #555;
    font-style: italic;
}

/* ── Collapsed "how is my class calculated" disclosure ───────────────────────── */
.calc-explainer-details {
    margin-bottom: var(--spacing-unit);
}

.calc-explainer-details summary {
    cursor: pointer;
    font-weight: 600;
    color: var(--secondary-color);
    padding: calc(var(--spacing-unit) * 0.4) 0;
}

.calc-explainer-details summary:hover {
    text-decoration: underline;
}

.calc-explainer-details .instructions-box {
    margin-top: calc(var(--spacing-unit) * 0.5);
    margin-bottom: 0;
}
```

- [ ] **Step 3: Manual verification**

There is no JS/HTML test harness in this repo (confirmed: only PHPUnit exists, scoped to `db.php`/`view_helpers.php`/`tech-sheet-*.php`). Verify by serving the static file and checking in a browser:

```bash
cd wcma-calculator && npx --yes serve . -l 8080
```

Open `http://localhost:8080/car-classing.html` and confirm:
- The two-step panel is the first thing visible below the header.
- "How is my class calculated?" is collapsed by default; clicking it reveals the original formula explanation unchanged.
- Enter a weight and HP — the real-time ratio/class results on the right still update immediately (this is the one thing that must not regress).

Stop the server (Ctrl+C) when done.

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/car-classing.html wcma-calculator/css/calculator.css
git commit -m "$(cat <<'EOF'
feat(calculator): lead landing page with two-step overview

Competitors need class declaration (once a season) and a tech sheet
(every event) — nothing previously said so before the calculator
form. The formula explanation moves behind a plain <details>
disclosure instead of being the first thing shown.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Landing page — replace icon-only tooltips with always-visible help text

**Files:**
- Modify: `wcma-calculator/car-classing.html:105-107,124-130,220-241,301-302`
- Modify: `wcma-calculator/js/ui-controller.js:19-27,139-196,1836`
- Modify: `wcma-calculator/css/calculator.css:175-217` (tooltip trigger/popover rules)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: nothing consumed by later tasks — purely removes a JS/CSS/HTML subsystem and replaces it with static text.

- [ ] **Step 1: Remove the six `.info-tooltip-trigger` buttons from `car-classing.html`, replacing each with visible help text**

Competition Weight (around line 105-107) — before:

```html
                        <label for="competition-weight">Competition Wgt (lbs) <span class="required">*</span>
                            <button type="button" class="info-tooltip-trigger" data-tooltip-key="competitionWeight" aria-label="What is Competition Weight?">?</button>
                        </label>
                        <div class="input-with-result">
                            <input type="number" id="competition-weight" name="competition_weight" required aria-required="true" min="0" step="1" placeholder="Enter weight">
                            <span class="inline-result" id="weight-result">--</span>
                        </div>
                        <span class="error-message" id="competition-weight-error"></span>
```

After:

```html
                        <label for="competition-weight">Competition Wgt (lbs) <span class="required">*</span></label>
                        <div class="input-with-result">
                            <input type="number" id="competition-weight" name="competition_weight" required aria-required="true" min="0" step="1" placeholder="Enter weight">
                            <span class="inline-result" id="weight-result">--</span>
                        </div>
                        <p class="field-help">Per WCMA regs, this is the minimum weight your car competes at &mdash; including driver and safety equipment &mdash; not just its static or curb weight.</p>
                        <span class="error-message" id="competition-weight-error"></span>
```

Dyno HP (around line 125-129) — before:

```html
                        <label for="dyno-hp">Dyno HP (optional)
                            <button type="button" class="info-tooltip-trigger" data-tooltip-key="dynoHp" aria-label="What is Dyno HP?">?</button>
                        </label>
                        <input type="number" id="dyno-hp" name="dyno_hp" min="0" step="1" placeholder="Enter dyno HP">
                        <span class="error-message" id="dyno-hp-error"></span>
```

After:

```html
                        <label for="dyno-hp">Dyno HP (optional)</label>
                        <input type="number" id="dyno-hp" name="dyno_hp" min="0" step="1" placeholder="Enter dyno HP">
                        <p class="field-help">Horsepower measured on a dynamometer. Optional, but if you have a dyno chart, providing this helps verify your Declared HP at tech inspection.</p>
                        <span class="error-message" id="dyno-hp-error"></span>
```

Calculation results box (around lines 220-247) — before:

```html
                        <div class="inline-calculation-summary">
                            <div class="summary-item">
                                <span class="summary-label">Weight Factor:
                                    <button type="button" class="info-tooltip-trigger" data-tooltip-key="weightFactor" aria-label="What is Weight Factor?">?</button>
                                </span>
                                <span class="summary-value" id="weight-factor-display">--</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Base Ratio:
                                    <button type="button" class="info-tooltip-trigger" data-tooltip-key="baseRatio" aria-label="What is Base Ratio?">?</button>
                                </span>
                                <span class="summary-value" id="inline-base-ratio">--</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Additional Mod Factors:
                                    <button type="button" class="info-tooltip-trigger" data-tooltip-key="modificationFactor" aria-label="What is Modification Factor?">?</button>
                                </span>
                                <span class="summary-value" id="additional-mods-display">--</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Modified Ratio:
                                    <button type="button" class="info-tooltip-trigger" data-tooltip-key="modifiedRatio" aria-label="What is Modified Ratio?">?</button>
                                </span>
                                <span class="summary-value" id="inline-modified-ratio">--</span>
                            </div>
                            <div class="summary-item highlight">
                                <span class="summary-label">Calculated Class:</span>
                                <span class="summary-value" id="inline-calculated-class">--</span>
                            </div>
                        </div>
```

After:

```html
                        <div class="inline-calculation-summary">
                            <div class="summary-item">
                                <span class="summary-label">Weight Factor:
                                    <span class="summary-help">An adjustment based on how light or heavy your car is compared to the typical range for its class.</span>
                                </span>
                                <span class="summary-value" id="weight-factor-display">--</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Base Ratio:
                                    <span class="summary-help">Competition Weight &divide; Declared HP, rounded to 2 decimals.</span>
                                </span>
                                <span class="summary-value" id="inline-base-ratio">--</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Additional Mod Factors:
                                    <span class="summary-help">The sum of your selected chassis, body, transmission, drivetrain, tire, and brake/suspension modifiers.</span>
                                </span>
                                <span class="summary-value" id="additional-mods-display">--</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Modified Ratio:
                                    <span class="summary-help">Base Ratio + Weight Factor + Modification Factor &mdash; determines your Calculated Class.</span>
                                </span>
                                <span class="summary-value" id="inline-modified-ratio">--</span>
                            </div>
                            <div class="summary-item highlight">
                                <span class="summary-label">Calculated Class:</span>
                                <span class="summary-value" id="inline-calculated-class">--</span>
                            </div>
                        </div>
```

(The full glossary sentences are preserved verbatim for the two form-field help texts above; the four result-box ones are shortened slightly since they now render inline inside an already-dense summary box rather than in a popover — this is a copy trim, not a content change requiring sign-off, since the fuller explanation for the same concepts remains available under the "How is my class calculated?" disclosure from Task 1.)

- [ ] **Step 2: Remove the now-unused shared popover element**

Around line 301-302, delete this line entirely:

```html
    <div id="info-tooltip-popover" class="info-tooltip-popover" role="tooltip" hidden></div>
```

- [ ] **Step 3: Remove the tooltip JS from `ui-controller.js`**

Delete the `TOOLTIP_CONTENT` constant (lines 19-27):

```javascript
// Glossary content for the (?) tooltip triggers
const TOOLTIP_CONTENT = {
    baseRatio: 'Competition Weight ÷ Declared HP, rounded to 2 decimals. This sets your starting class before any modifiers are applied.',
    weightFactor: 'An adjustment based on how light or heavy your car is compared to the typical range for its class — very light or very heavy cars get nudged to keep classing fair.',
    modificationFactor: 'The sum of all your selected chassis, body, transmission, drivetrain, tire, and brake/suspension modifiers.',
    modifiedRatio: 'Base Ratio + Weight Factor + Modification Factor. This final number determines your Calculated Class.',
    dynoHp: 'Horsepower measured on a dynamometer. Optional, but if you have a dyno chart, providing this helps verify your Declared HP at tech inspection.',
    competitionWeight: 'Per WCMA regs, this is the minimum weight your car competes at — including driver and safety equipment — not just its static or curb weight.'
};
```

Delete the entire `initInfoTooltips()` function (lines 139-196, the full block from `/**\n * Wire up the shared (?) glossary tooltip popover...` through its closing `}`).

Remove its call site at (former) line 1836, inside `initFunction()`:

Before:

```javascript
        initializeEventListeners();
        initInfoTooltips();
        updateFormData();
```

After:

```javascript
        initializeEventListeners();
        updateFormData();
```

- [ ] **Step 4: Replace tooltip CSS with `.field-help`/`.summary-help` styles in `calculator.css`**

Delete the `/* Info tooltip trigger + popover */` block (the `.info-tooltip-trigger`, its `:hover`/`:focus-visible`/`.is-active` rule, `.info-tooltip-popover`, and `.info-tooltip-popover[hidden]` — originally lines 175-217).

Add in its place:

```css
/* ── Always-visible field help text (replaces icon-only tooltips) ────────────── */
.field-help {
    font-size: 0.95rem;
    color: #555;
    line-height: 1.4;
    margin: calc(var(--spacing-unit) * 0.25) 0 0;
}

.summary-help {
    display: block;
    font-size: 0.8rem;
    font-weight: normal;
    color: rgba(255, 255, 255, 0.75);
    margin-top: 0.15rem;
}
```

- [ ] **Step 5: Manual verification**

```bash
cd wcma-calculator && npx --yes serve . -l 8080
```

Open `http://localhost:8080/car-classing.html` and confirm:
- No `?` icon buttons remain anywhere on the page.
- Plain help text is visible under Competition Weight and Dyno HP without clicking anything.
- The four result-box labels show their help text inline, and the real-time weight/HP/modifier recalculation still updates the displayed values as you type (again: the one thing that must not regress).
- Open the browser console — no JS errors from a missing `info-tooltip-popover` element.

Also confirm no leftover references:

```bash
grep -rn "info-tooltip" wcma-calculator/car-classing.html wcma-calculator/js/ui-controller.js wcma-calculator/css/calculator.css
```

Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/car-classing.html wcma-calculator/js/ui-controller.js wcma-calculator/css/calculator.css
git commit -m "$(cat <<'EOF'
fix(calculator): replace icon-only tooltips with visible help text

Small circular "?" buttons are an easy-to-miss pattern for a less
tech-comfortable audience. The same glossary content now renders as
plain text directly under each field/result, no click required to
discover it.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Sitewide typography baseline — raise small utility text off the floor

**Files:**
- Modify: `wcma-calculator/css/calculator.css` (multiple selectors, listed below)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks. Pure CSS value changes, shared by every page since they all load this one stylesheet (`car-classing.html`, `account.php`, `tech-sheets.php`, `admin.php`, `auth.php` all reference `css/calculator.css`) — no per-page edits needed beyond this file.

- [ ] **Step 1: Apply each font-size change below (exact selector → old value → new value)**

| Selector | Old | New |
|---|---|---|
| `label` | `font-size: 0.9rem;` | `font-size: 1rem;` |
| `input[type="text"], input[type="email"], input[type="number"], input[type="password"], select, textarea` | `font-size: 0.95rem;` | `font-size: 1rem;` |
| `.error-message` | `font-size: 0.85rem;` | `font-size: 1rem;` |
| `.section-note, .file-note` | `font-size: 0.85rem;` | `font-size: 1rem;` |
| `.instructions-box p` | `font-size: 0.85rem;` | `font-size: 1rem;` |
| `.how-it-works-steps` | `font-size: 0.85rem;` | `font-size: 1rem;` |
| `.rulebook-disclaimer` | `font-size: 0.8rem;` | `font-size: 0.95rem;` |
| `.modifier-explainer` | `font-size: 0.78rem;` | `font-size: 0.95rem;` |
| `.field-note` | `font-size: 0.75rem;` | `font-size: 0.9rem;` |
| `.file-info` | `font-size: 0.85rem;` | `font-size: 1rem;` |
| `.inline-result` | `font-size: 0.85rem;` | `font-size: 0.95rem;` |
| `.modifier-value` | `font-size: 0.85rem;` | `font-size: 0.95rem;` |
| `.submit-note` | `font-size: 0.85rem;` | `font-size: 0.95rem;` |
| `.checkbox-item label` | `font-size: 0.9rem;` | `font-size: 1rem;` |
| `.data-table th` | `font-size: 0.85rem;` | `font-size: 0.95rem;` |
| `.data-table td` | `font-size: 0.9rem;` | `font-size: 1rem;` |
| `.table-search` | `font-size: 0.9rem;` | `font-size: 1rem;` |

`.inline-result` and `.modifier-value` are two separate rule blocks with identical property text — when editing, match each by its full selector block (e.g. `.inline-result {\n    font-weight: 600;\n    color: var(--secondary-color);\n    font-size: 0.85rem;\n ...`) so the edit lands on the correct one; do not use a bare `font-size: 0.85rem;` find-replace across the file, since several unrelated rules share that exact value.

- [ ] **Step 2: Enlarge the checkbox tap target**

The `.checkbox-item input[type="checkbox"]` rule currently only sets `margin-top` and `cursor` (relying on the browser default ~13px checkbox). Add explicit sizing:

Before:

```css
.checkbox-item input[type="checkbox"] {
    margin-top: 0.2rem;
    cursor: pointer;
}
```

After:

```css
.checkbox-item input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin-top: 0.1rem;
    cursor: pointer;
}
```

- [ ] **Step 3: Manual verification**

```bash
cd wcma-calculator && npx --yes serve . -l 8080
```

Open `http://localhost:8080/car-classing.html`, then log in and open `http://localhost:8080/account.php` (requires a test account — see `tests/DbUsersTest.php` for how test users are constructed if you need one, or use the running app's registration flow against a local SQLite DB). Visually confirm labels, inputs, and table text read larger and less cramped than before, and that nothing overflows its container at both desktop and a narrow (< 480px) viewport width.

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/css/calculator.css
git commit -m "$(cat <<'EOF'
fix(css): raise small utility text off a sub-13px floor

Labels, help text, and table text ran as small as 0.7-0.85rem
(11-14px) throughout. Raised to a 1rem/0.95rem floor for an older,
less tech-comfortable audience — shared calculator.css means every
page (calculator, My Cars, tech sheets, admin) inherits this.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Terminology pass — "Class Declaration" instead of "Submission"

**Files:**
- Modify: `wcma-calculator/account.php:249,255`
- Modify: `wcma-calculator/car-classing.php:58` (email subject line)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Rename the submission detail page's title/header in `account.php`**

Around lines 249 and 255, in `renderAccountViewPage()`:

Before:

```php
<title>Submission #<?= (int)$s['id'] ?> — My Cars</title>
```
```php
  <?php renderSiteHeader('Submission #' . $s['id'], '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>
```

After:

```php
<title>Class Declaration — <?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></title>
```
```php
  <?php renderSiteHeader('Class Declaration — ' . trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model']), '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>
```

(`renderSiteHeader()` already HTML-escapes its `$title` argument via `h()` internally — see `view_helpers.php:35` — so passing the raw concatenated string here is correct and consistent with how every other call site uses it.)

- [ ] **Step 2: Update the classing-submission email subject in `car-classing.php`**

Around line 58:

Before:

```php
$subject = 'WCMA Classing Calculator Submission - ' . htmlspecialchars($name) . ' - ' . date('M j, Y');
```

After:

```php
$subject = 'WCMA Class Declaration - ' . htmlspecialchars($name) . ' - ' . date('M j, Y');
```

- [ ] **Step 3: Verify no other competitor-facing "Submission" text remains**

```bash
grep -rn "My Submissions\|Submission #" wcma-calculator/car-classing.html wcma-calculator/account.php wcma-calculator/tech-sheets.php
```

Expected: no output (the only remaining "Submission #" usage in the codebase is `admin.php`, which is explicitly out of scope per Global Constraints).

- [ ] **Step 4: Commit**

```bash
git add wcma-calculator/account.php wcma-calculator/car-classing.php
git commit -m "$(cat <<'EOF'
fix(calculator): rename "Submission" to "Class Declaration" in competitor copy

Standardizes on two competitor-facing nouns everywhere: Class
Declaration and Tech Sheet. Internal "submission" terminology now
only appears in code/DB, not in anything a driver reads.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Anonymous → account nudge copy

**Files:**
- Modify: `wcma-calculator/js/form-handler.js:320-330`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Tighten the post-submission nudge copy in `maybeShowAccountNudge()`**

Before (lines 320-330):

```javascript
    const nudge = document.createElement('p');
    nudge.className = 'post-submit-nudge';
    nudge.style.marginTop = '0.5rem';
    nudge.appendChild(document.createTextNode('Want to track this car’s history? '));

    const link = document.createElement('a');
    const params = new URLSearchParams({ action: 'register', email: submittedEmail || '' });
    link.href = `auth.php?${params.toString()}`;
    link.textContent = 'Create a free account';
    nudge.appendChild(link);
```

After:

```javascript
    const nudge = document.createElement('p');
    nudge.className = 'post-submit-nudge';
    nudge.style.marginTop = '0.5rem';
    nudge.appendChild(document.createTextNode('Create a free account so this class declaration is saved — you’ll need it when you submit a tech sheet for an upcoming event. '));

    const link = document.createElement('a');
    const params = new URLSearchParams({ action: 'register', email: submittedEmail || '' });
    link.href = `auth.php?${params.toString()}`;
    link.textContent = 'Create a free account';
    nudge.appendChild(link);
```

- [ ] **Step 2: Manual verification**

```bash
cd wcma-calculator && npx --yes serve . -l 8080
```

Submit the calculator form anonymously (no login) with a valid email and confirm the success message now shows the new sentence followed by a "Create a free account" link pointing at `auth.php?action=register&email=<the+email+you+entered>`.

- [ ] **Step 3: Commit**

```bash
git add wcma-calculator/js/form-handler.js
git commit -m "$(cat <<'EOF'
fix(calculator): explain why to create an account in the guest nudge

Names the concrete reason (you'll need it for tech sheets) instead
of the vaguer "track this car's history", tying the nudge back to
the two-step framing introduced on the landing page.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: My Cars grouping logic — pure function + tests (TDD)

**Files:**
- Modify: `wcma-calculator/view_helpers.php` (append new function)
- Test: `wcma-calculator/tests/AccountCarGroupingTest.php` (new)

**Interfaces:**
- Consumes: rows shaped like `db_get_user_submissions()`, `db_get_user_tech_sheets()`, `db_get_active_events()`, and an `[event_id => name]` map from `db_get_all_events()` (all already defined in `db.php`, unchanged by this plan).
- Produces: `buildCarTechSheetGroups(array $submissions, array $techSheets, array $activeEvents, array $eventNames): array`, returning `['cars' => [['submission' => array, 'lines' => [['event_id' => int, 'event_name' => string, 'event_date' => ?string, 'sheet' => ?array], ...]], ...], 'orphanSheets' => array]`. Task 7 consumes this exact shape.

- [ ] **Step 1: Write the failing tests**

Create `wcma-calculator/tests/AccountCarGroupingTest.php`:

```php
<?php
// wcma-calculator/tests/AccountCarGroupingTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../view_helpers.php';

final class AccountCarGroupingTest extends TestCase
{
    private function submission(int $id, string $make = 'Mazda', string $model = 'MX-5'): array {
        return ['id' => $id, 'year' => '2020', 'make' => $make, 'model' => $model, 'calculated_class' => 'GT3', 'submitted_at' => '2026-08-03 10:00:00'];
    }

    private function techSheet(int $id, int $submissionId, int $eventId, string $status = 'submitted'): array {
        return ['id' => $id, 'submission_id' => $submissionId, 'event_id' => $eventId, 'status' => $status, 'car_make' => 'Mazda', 'car_model' => 'MX-5', 'car_number' => '42'];
    }

    private function event(int $id, string $name, string $date = '2026-10-04'): array {
        return ['id' => $id, 'name' => $name, 'event_date' => $date];
    }

    public function testCarWithNoTechSheetsShowsNotSubmittedForEachActiveEvent(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $this->assertCount(1, $groups['cars']);
        $this->assertCount(1, $groups['cars'][0]['lines']);
        $this->assertNull($groups['cars'][0]['lines'][0]['sheet']);
        $this->assertSame('Fall Sprint', $groups['cars'][0]['lines'][0]['event_name']);
    }

    public function testCarWithSubmittedNotReviewedSheetShowsItsStatus(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 10, 'submitted')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $sheet = $groups['cars'][0]['lines'][0]['sheet'];
        $this->assertNotNull($sheet);
        $this->assertSame('submitted', $sheet['status']);
    }

    public function testCarWithReviewedSheetShowsTechedStatus(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 10, 'teched')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $this->assertSame('teched', $groups['cars'][0]['lines'][0]['sheet']['status']);
    }

    public function testCarWithSheetsAgainstMultipleEventsShowsOneLineEach(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 10, 'submitted'), $this->techSheet(101, 1, 11, 'teched')],
            [$this->event(10, 'Fall Sprint'), $this->event(11, 'Summer Enduro')],
            [10 => 'Fall Sprint', 11 => 'Summer Enduro']
        );

        $this->assertCount(2, $groups['cars'][0]['lines']);
        $eventNames = array_map(fn($l) => $l['event_name'], $groups['cars'][0]['lines']);
        $this->assertSame(['Fall Sprint', 'Summer Enduro'], $eventNames);
    }

    public function testSheetForInactiveEventStillShownAfterActiveEventLines(): void
    {
        // Event 12 is no longer active (absent from $activeEvents) but the sheet still exists.
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 1, 12, 'teched')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint', 12 => 'Old Event']
        );

        $this->assertCount(2, $groups['cars'][0]['lines']);
        $this->assertSame('Old Event', $groups['cars'][0]['lines'][1]['event_name']);
        $this->assertSame('teched', $groups['cars'][0]['lines'][1]['sheet']['status']);
    }

    public function testTechSheetWithUnknownSubmissionGoesToOrphans(): void
    {
        $groups = buildCarTechSheetGroups(
            [$this->submission(1)],
            [$this->techSheet(100, 999, 10, 'submitted')],
            [$this->event(10, 'Fall Sprint')],
            [10 => 'Fall Sprint']
        );

        $this->assertNull($groups['cars'][0]['lines'][0]['sheet']);
        $this->assertCount(1, $groups['orphanSheets']);
        $this->assertSame(100, (int)$groups['orphanSheets'][0]['id']);
    }
}
```

- [ ] **Step 2: Run the tests and verify they fail**

```bash
cd wcma-calculator && php phpunit.phar tests/AccountCarGroupingTest.php
```

Expected: fatal error / failures — `buildCarTechSheetGroups()` does not exist yet.

- [ ] **Step 3: Implement `buildCarTechSheetGroups()` in `view_helpers.php`**

Append to the end of `wcma-calculator/view_helpers.php`:

```php
/**
 * Groups a competitor's classed cars (submissions) with their tech-sheet
 * status per active event, for the My Cars page. Pure data transformation —
 * no DB access, no HTML — so the grouping logic is unit-testable
 * independent of rendering (see tests/AccountCarGroupingTest.php).
 *
 * @param array $submissions  Rows from db_get_user_submissions()
 * @param array $techSheets   Rows from db_get_user_tech_sheets()
 * @param array $activeEvents Rows from db_get_active_events()
 * @param array $eventNames   [event_id => name] map from db_get_all_events(),
 *                             covering past/inactive events too — used as a
 *                             fallback when a sheet's event has since been
 *                             deactivated.
 * @return array{cars: array, orphanSheets: array}
 */
function buildCarTechSheetGroups(array $submissions, array $techSheets, array $activeEvents, array $eventNames): array {
    $knownSubmissionIds = [];
    foreach ($submissions as $s) {
        $knownSubmissionIds[(int)$s['id']] = true;
    }

    $sheetsBySubmission = [];
    $orphanSheets = [];
    foreach ($techSheets as $ts) {
        $subId = (int)$ts['submission_id'];
        if (isset($knownSubmissionIds[$subId])) {
            $sheetsBySubmission[$subId][] = $ts;
        } else {
            // Defensive: shouldn't happen given the FK, but a submission
            // could be deleted out from under a tech sheet in edge cases.
            $orphanSheets[] = $ts;
        }
    }

    $cars = [];
    foreach ($submissions as $s) {
        $subId = (int)$s['id'];
        $sheetsByEvent = [];
        foreach ($sheetsBySubmission[$subId] ?? [] as $ts) {
            $sheetsByEvent[(int)$ts['event_id']] = $ts;
        }

        $lines = [];
        foreach ($activeEvents as $e) {
            $eventId = (int)$e['id'];
            $lines[] = [
                'event_id' => $eventId,
                'event_name' => $e['name'],
                'event_date' => $e['event_date'],
                'sheet' => $sheetsByEvent[$eventId] ?? null,
            ];
            unset($sheetsByEvent[$eventId]);
        }

        // Any sheets left are tied to an event no longer active — still show them.
        foreach ($sheetsByEvent as $eventId => $ts) {
            $lines[] = [
                'event_id' => $eventId,
                'event_name' => $eventNames[$eventId] ?? 'Unknown event',
                'event_date' => null,
                'sheet' => $ts,
            ];
        }

        $cars[] = ['submission' => $s, 'lines' => $lines];
    }

    return ['cars' => $cars, 'orphanSheets' => $orphanSheets];
}
```

- [ ] **Step 4: Run the tests and verify they pass**

```bash
cd wcma-calculator && php phpunit.phar tests/AccountCarGroupingTest.php
```

Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Run the full suite to confirm nothing else broke**

```bash
cd wcma-calculator && php phpunit.phar
```

Expected: all tests pass (the new file adds 6; every pre-existing test file is untouched).

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/view_helpers.php wcma-calculator/tests/AccountCarGroupingTest.php
git commit -m "$(cat <<'EOF'
feat(account): add pure grouping helper for My Cars tech-sheet nesting

buildCarTechSheetGroups() pairs each classed car with its tech-sheet
status per active event (plus a fallback bucket for orphaned
sheets), kept separate from account.php's rendering so the grouping
logic has direct unit test coverage. Not yet wired into the page.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: My Cars page — render car-cards with nested tech-sheet status

**Files:**
- Modify: `wcma-calculator/account.php:79-215` (`handleAccountList()` and `renderAccountListPage()`)
- Modify: `wcma-calculator/css/calculator.css` (append car-card rules)

**Interfaces:**
- Consumes: `buildCarTechSheetGroups()` from Task 6 (`view_helpers.php`, already required by `account.php:5`).
- Produces: nothing consumed by later tasks — this is the last task in the plan.

- [ ] **Step 1: Rewrite `handleAccountList()`**

Before (lines 79-102):

```php
function handleAccountList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $techSheets = db_get_user_tech_sheets($pdo, $user['id']);
    $totalCount = db_count_user_drafts($pdo, $user['id']) + db_count_user_submissions($pdo, $user['id']);

    $eventNames = [];
    foreach (db_get_all_events($pdo) as $e) {
        $eventNames[(int)$e['id']] = $e['name'];
    }

    $rows = [];
    foreach ($drafts as $d) {
        $rows[] = ['type' => 'draft', 'sort_key' => $d['updated_at'], 'data' => $d];
    }
    foreach ($submissions as $s) {
        $rows[] = ['type' => 'submission', 'sort_key' => $s['submitted_at'], 'data' => $s];
    }
    usort($rows, fn($a, $b) => strcmp($b['sort_key'], $a['sort_key']));

    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($rows, $totalCount, $csrf, $flash, $techSheets, $eventNames);
}
```

After:

```php
function handleAccountList(PDO $pdo, array $user): void {
    $drafts = db_get_user_drafts($pdo, $user['id']);
    $submissions = db_get_user_submissions($pdo, $user['id']);
    $techSheets = db_get_user_tech_sheets($pdo, $user['id']);
    $activeEvents = db_get_active_events($pdo);
    $totalCount = db_count_user_drafts($pdo, $user['id']) + db_count_user_submissions($pdo, $user['id']);

    $eventNames = [];
    foreach (db_get_all_events($pdo) as $e) {
        $eventNames[(int)$e['id']] = $e['name'];
    }

    $carGroups = buildCarTechSheetGroups($submissions, $techSheets, $activeEvents, $eventNames);

    $csrf = generateCsrfToken();
    $flash = getFlash();
    renderAccountListPage($drafts, $carGroups, $totalCount, $csrf, $flash);
}
```

- [ ] **Step 2: Rewrite `renderAccountListPage()`**

Before (lines 104-215, the full function):

```php
function renderAccountListPage(array $rows, int $count, string $csrf, ?array $flash, array $techSheets = [], array $eventNames = []): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Cars — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('My Cars', renderCommonNav('account')); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > MY_CARS_SOFT_CAP): ?>
  <div class="form-messages show info">You have <?= (int)$count ?> saved cars — consider deleting some older ones.</div>
  <?php endif; ?>
  <?php if (!empty($rows)): ?>
  <div class="list-toolbar">
    <input type="search" id="my-cars-search" class="table-search" placeholder="Search my cars…" aria-label="Search my cars">
  </div>
  <?php endif; ?>
  <table class="data-table" id="my-cars-table">
    <thead>
      <tr>
        <th data-sort data-sort-type="text">Type</th>
        <th data-sort data-sort-type="date">Updated</th>
        <th data-sort data-sort-type="text">Vehicle</th>
        <th data-sort data-sort-type="text">Class</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
      <tr><td colspan="5" class="empty-row">No cars yet — save a draft or submit the calculator to get started.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $row): ?>
        <?php if ($row['type'] === 'draft'): ?>
        <?php $d = $row['data']; ?>
      <tr>
        <td><span class="badge-draft">Draft</span></td>
        <td data-sort-value="<?= h($d['updated_at']) ?>"><?= h(date('M j, Y H:i', strtotime($d['updated_at']))) ?></td>
        <td><?= h($d['label'] ?: 'Untitled') ?></td>
        <td>—</td>
        <td class="actions">
          <a href="car-classing.html?draft=<?= (int)$d['id'] ?>">Edit</a>
          <form method="post" action="account.php?action=draft-delete" style="display:inline"
                data-confirm="Delete this draft?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
        <?php else: ?>
        <?php $s = $row['data']; ?>
      <tr>
        <td>Submitted</td>
        <td data-sort-value="<?= h($s['submitted_at']) ?>"><?= h(date('M j, Y H:i', strtotime($s['submitted_at']))) ?></td>
        <td><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></td>
        <td><strong><?= h($s['calculated_class'] ?? '—') ?></strong></td>
        <td class="actions">
          <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View</a>
          <a href="tech-sheets.php?action=new&submission_id=<?= (int)$s['id'] ?>">Submit Tech Sheet</a>
          <form method="post" action="account.php?action=delete" style="display:inline"
                data-confirm="Permanently delete this submission and its files?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No cars match your search.</p>

  <h2 style="margin-top:2rem">My Tech Sheets</h2>
  <?php if (empty($techSheets)): ?>
  <p class="empty-row">No tech sheets submitted yet.</p>
  <?php else: ?>
  <table class="data-table" id="tech-sheets-table">
    <thead><tr><th>Event</th><th>Vehicle</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($techSheets as $ts): $eventName = $eventNames[(int)$ts['event_id']] ?? null; ?>
      <tr>
        <td><?= h($eventName ?? 'Unknown event') ?></td>
        <td><?= h(trim($ts['car_make'] . ' ' . $ts['car_model'] . ' #' . $ts['car_number'])) ?></td>
        <td><?= h(ucfirst($ts['sheet_type'])) ?></td>
        <td class="<?= $ts['status'] === 'teched' ? 'badge-ok' : 'badge-fail' ?>"><?= $ts['status'] === 'teched' ? 'Reviewed' : 'Submitted' ?></td>
        <td class="actions"><a href="tech-sheets.php?action=view&id=<?= (int)$ts['id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('my-cars-search'), document.getElementById('my-cars-table'));
  WcmaTableTools.enableSort(document.getElementById('my-cars-table'));
</script>
</body>
</html><?php
}
```

After:

```php
function renderAccountListPage(array $drafts, array $carGroups, int $count, string $csrf, ?array $flash): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Cars — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('My Cars', renderCommonNav('account')); ?>
  <?php if ($flash): ?>
  <div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($count > MY_CARS_SOFT_CAP): ?>
  <div class="form-messages show info">You have <?= (int)$count ?> saved cars — consider deleting some older ones.</div>
  <?php endif; ?>

  <h2>My Cars</h2>
  <?php if (empty($carGroups['cars'])): ?>
  <p class="empty-row">No classed cars yet — use the calculator to declare a class.</p>
  <?php else: ?>
    <?php foreach ($carGroups['cars'] as $car): $s = $car['submission']; ?>
    <div class="car-card">
      <div class="car-card-header">
        <span class="car-card-vehicle"><?= h(trim($s['year'] . ' ' . $s['make'] . ' ' . $s['model'])) ?></span>
        <span class="car-card-class"><?= h($s['calculated_class'] ?? '—') ?></span>
      </div>
      <p class="car-card-meta">Class declared <?= h(date('M j, Y', strtotime($s['submitted_at']))) ?></p>
      <div class="car-card-actions">
        <a href="account.php?action=view&id=<?= (int)$s['id'] ?>">View declaration</a>
        <form method="post" action="account.php?action=delete" style="display:inline"
              data-confirm="Permanently delete this class declaration and its files?">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button type="submit" class="link-button">Delete</button>
        </form>
      </div>
      <?php if (!empty($car['lines'])): ?>
      <ul class="car-card-tech-list">
        <?php foreach ($car['lines'] as $line): $sheet = $line['sheet']; ?>
        <?php $eventLabel = h($line['event_name']) . ($line['event_date'] ? ' (' . h(date('M j', strtotime($line['event_date']))) . ')' : ''); ?>
        <li class="car-card-tech-line">
          <?php if ($sheet === null): ?>
            Tech sheet for <strong><?= $eventLabel ?></strong>: <span class="badge-pending">not submitted</span> —
            <a href="tech-sheets.php?action=new&submission_id=<?= (int)$s['id'] ?>">Submit now</a>
          <?php else: ?>
            Tech sheet for <strong><?= $eventLabel ?></strong>:
            <span class="<?= $sheet['status'] === 'teched' ? 'badge-ok' : 'badge-fail' ?>"><?= $sheet['status'] === 'teched' ? 'submitted, reviewed' : 'submitted' ?></span> —
            <a href="tech-sheets.php?action=view&id=<?= (int)$sheet['id'] ?>">View</a>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="car-card-no-events">No upcoming events open for tech sheet submission yet.</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if (!empty($carGroups['orphanSheets'])): ?>
  <h2 style="margin-top:2rem">Other Tech Sheets</h2>
  <ul class="car-card-tech-list">
    <?php foreach ($carGroups['orphanSheets'] as $ts): ?>
    <li class="car-card-tech-line">
      <?= h(trim($ts['car_make'] . ' ' . $ts['car_model'] . ' #' . $ts['car_number'])) ?> —
      <span class="<?= $ts['status'] === 'teched' ? 'badge-ok' : 'badge-fail' ?>"><?= $ts['status'] === 'teched' ? 'submitted, reviewed' : 'submitted' ?></span> —
      <a href="tech-sheets.php?action=view&id=<?= (int)$ts['id'] ?>">View</a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <h2 style="margin-top:2rem">Drafts</h2>
  <?php if (!empty($drafts)): ?>
  <div class="list-toolbar">
    <input type="search" id="my-drafts-search" class="table-search" placeholder="Search my drafts…" aria-label="Search my drafts">
  </div>
  <?php endif; ?>
  <table class="data-table" id="my-drafts-table">
    <thead>
      <tr>
        <th data-sort data-sort-type="date">Updated</th>
        <th data-sort data-sort-type="text">Vehicle</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($drafts)): ?>
      <tr><td colspan="3" class="empty-row">No drafts yet — start the calculator and save your progress to come back to it later.</td></tr>
    <?php else: ?>
      <?php foreach ($drafts as $d): ?>
      <tr>
        <td data-sort-value="<?= h($d['updated_at']) ?>"><?= h(date('M j, Y H:i', strtotime($d['updated_at']))) ?></td>
        <td><?= h($d['label'] ?: 'Untitled') ?></td>
        <td class="actions">
          <a href="car-classing.html?draft=<?= (int)$d['id'] ?>">Edit</a>
          <form method="post" action="account.php?action=draft-delete" style="display:inline"
                data-confirm="Delete this draft?">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button type="submit" class="link-button">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="no-results-message" hidden>No drafts match your search.</p>
</div>
<script src="js/table-tools.js"></script>
<script src="js/confirm-modal.js"></script>
<script src="js/form-feedback.js"></script>
<script>
  WcmaTableTools.enableSearch(document.getElementById('my-drafts-search'), document.getElementById('my-drafts-table'));
  WcmaTableTools.enableSort(document.getElementById('my-drafts-table'));
</script>
</body>
</html><?php
}
```

(This drops the `Type`/`Class` columns from the drafts table, since every remaining row is a draft and a draft never has a class — `Type: Draft` and `Class: —` were dead weight once submissions no longer share the table. `MY_CARS_SOFT_CAP`'s combined drafts+submissions count check is unchanged.)

- [ ] **Step 3: Add car-card CSS**

Append to `calculator.css`:

```css
/* ── My Cars: one card per classed car, tech sheets nested per event ─────────── */
.car-card {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
    padding: calc(var(--spacing-unit) * 1.2);
    margin-bottom: calc(var(--spacing-unit) * 1);
}

.car-card-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: calc(var(--spacing-unit) * 0.5);
}

.car-card-vehicle {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--primary-color);
}

.car-card-class {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--secondary-color);
}

.car-card-meta {
    color: #666;
    font-size: 0.95rem;
    margin: 0.2rem 0 0.8rem;
}

.car-card-actions {
    margin-bottom: 0.8rem;
}

.car-card-actions a,
.car-card-actions .link-button {
    margin-right: 1rem;
    font-size: 0.95rem;
}

.car-card-tech-list {
    list-style: none;
    margin: 0;
    padding: 0.6rem 0 0;
    border-top: 1px solid var(--border-color);
}

.car-card-tech-line {
    font-size: 1rem;
    padding: 0.4rem 0;
    line-height: 1.5;
}

.car-card-no-events {
    color: #888;
    font-size: 0.95rem;
    font-style: italic;
    margin: 0.6rem 0 0;
}

.badge-pending { color: var(--warning-color); font-weight: bold; }
```

- [ ] **Step 4: Run the full PHPUnit suite**

```bash
cd wcma-calculator && php phpunit.phar
```

Expected: all tests still pass — `account.php` has no existing test coverage of its own (it's a router/render file, not unit-tested), so this confirms Task 6's grouping function and every other existing test are unaffected.

- [ ] **Step 5: Manual verification**

```bash
cd wcma-calculator && npx --yes serve . -l 8080
```

Log into a test account with at least one class declaration and, ideally, one active event configured (use `admin.php`'s Events page, or insert directly via the app's existing account/admin flows) and confirm:
- One card per classed car, showing vehicle, class, and declared date.
- A "not submitted" line with a working "Submit now" link for any active event without a tech sheet yet.
- A car with a submitted-but-not-reviewed sheet shows "submitted"; a reviewed one shows "submitted, reviewed".
- A car with sheets against two different events shows two lines.
- Delete/View actions on the card still work exactly as they did in the old table row.
- The Drafts table below still works: search, sort, edit, delete.
- No leftover "My Tech Sheets" table/heading anywhere on the page.

- [ ] **Step 6: Commit**

```bash
git add wcma-calculator/account.php wcma-calculator/css/calculator.css
git commit -m "$(cat <<'EOF'
feat(account): nest tech-sheet status under each car on My Cars

Replaces the separate "My Tech Sheets" table with one card per
classed car, listing its tech-sheet status per active event inline
— so the class-declaration -> tech-sheet relationship is visible
without the competitor needing to already know it exists. Drafts
keep their own simple table below, unchanged in behavior.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Notes

- **Spec coverage:** §1a/§1b/§1d → Task 1; §1c → Task 2; §3 → Task 3; §2 → Task 4; §5 → Task 5; §4 → Tasks 6-7. Every numbered section of the spec has a task.
- **Real-time recalculation constraint:** explicitly called out as a manual-verification checkpoint in Tasks 1, 2, and 3 — the only tasks that touch the calculator page.
- **Type/name consistency:** `buildCarTechSheetGroups()` is defined once in Task 6 with the exact return shape (`cars[].lines[].sheet`, `orphanSheets`) that Task 7's `renderAccountListPage()` consumes — checked against both task bodies above, names match throughout (`$carGroups['cars']`, `$car['lines']`, `$line['sheet']`, `$carGroups['orphanSheets']`).
- **No placeholders:** every step above contains literal file content, not a description of what to write.
