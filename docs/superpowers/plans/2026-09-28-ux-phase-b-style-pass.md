# UX Phase B — Shared Style Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every member-facing page on a phone has buttons that look usable (never grey unless disabled), tap targets of at least 44px, text of at least 16px, and readable contrast — enforced by an automated phone audit.

**Architecture:** Every page has `<body class="hub">` and loads `css/calculator.css` (legacy blue, all-caps) and then `css/hub.css`. Phase B adds one clearly marked section at the end of `hub.css` that remaps the legacy classes under `body.hub`, so auth, tech sheet, pre-tech, gear and admin pages change without markup edits. A Playwright audit (`tests/ux/`) drives the member flow at 375px and fails on each rule; it is the test that goes red first and green last.

**Tech Stack:** CSS, PHP 8.3 (one markup wrapper), Node + Playwright 1.63.0 (Chromium) for the audit, PHPUnit (`php phpunit.phar`).

**Spec:** `docs/superpowers/specs/2026-09-28-mobile-ux-older-users-design.md` (Phase B, §B1–B4)

## Global Constraints

- Minimum tap target: 44px tall (inputs 48px). Radios/checkboxes 24px, their label row is the tap target (≥ 44px).
- No member-facing text below 16px. Input text ≥ 16px.
- Contrast: AA 4.5:1 minimum for all text.
- Enabled controls are never grey. Disabled = dashed outline, `--hub-ink-2` text, `cursor: not-allowed`.
- Button text is sentence case: `text-transform: none` everywhere.
- Use the existing tokens in `css/hub.css` `:root` (`--hub-red`, `--hub-red-ink`, `--hub-ink`, `--hub-ink-2`, `--hub-ok-ink`, `--hub-ok-bg`, `--hub-warn-ink`, `--hub-warn-bg`, `--hub-todo-bg`, `--hub-paper`, `--hub-card`, `--hub-line`). No new colours.
- All Phase B CSS goes in one section at the end of `css/hub.css`, headed `/* ── Phase B: shared style pass (mobile UX spec 2026-09-28 §B) ── */`, scoped with `body.hub`.
- PHPUnit stays green (baseline 938). Run from `wcma-calculator/`: `php phpunit.phar`.
- Audit: `bash wcma-calculator/tests/ux/run-audit.sh` from the repo root (needs `npm install` once in `wcma-calculator/tests/ux/`).
- Branch `ux-phase-b` from `main`. Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. Pages the audit doesn't visit (admin, inspector, gear photo page, calculator) must not break: the remapped `.btn` styles must still look like buttons and forms must still submit. Checked by screenshots in Task 5.
2. Disabled buttons (pre-tech "Submit for pre-tech review" before all photos) must look disabled and different from enabled ones. Pinned by the audit's disabled-vs-enabled check in Task 1.
3. The printed tech sheet (Print button) must keep its print sizes; the 16px floor applies on screen only. Checked in Task 4 (`@media screen`).
4. At 150% text size nothing overflows sideways on the audited pages. Pinned by the audit's `--scale=1.5` pass in Task 1.
5. The Garage season cards from Phase A (56px, stacked on narrow screens) must keep their layout after the global radio/label rules. Pinned by the audit visiting Add a car in Task 1.

---

### Task 1: The phone audit (red first)

**Files:**
- Create: `wcma-calculator/tests/ux/package.json`, `wcma-calculator/tests/ux/.gitignore`, `wcma-calculator/tests/ux/audit.mjs`, `wcma-calculator/tests/ux/run-audit.sh`, `wcma-calculator/tests/ux/README.md`

**Interfaces:**
- Produces: `run-audit.sh` exits 0 when every audited page passes, 1 otherwise, printing `ok  <page>` or `FAIL <page>` with one `- <problem>` line per problem. Pages: `landing`, `sign in`, `create account`, `home`, `add a car`, `ice tech sheet`, `submitted sheet`, `pre-tech photos`, `car page`, each also at `@150%`.

- [ ] **Step 1: Create the package files**

`wcma-calculator/tests/ux/package.json`:

```json
{
  "private": true,
  "type": "module",
  "description": "Phone audit for the WCMA Hub (mobile UX spec 2026-09-28 §B4)",
  "devDependencies": { "playwright": "1.63.0" }
}
```

`wcma-calculator/tests/ux/.gitignore`:

```
node_modules/
package-lock.json
```

`wcma-calculator/tests/ux/README.md`:

```markdown
# Phone audit

Walks the member flow (sign up → add an ice car → ice tech sheet → submit → pre-tech) at 375px wide,
once at normal text size and once at 150%, and fails on: sideways scrolling, tap targets under
44px, radios/checkboxes under 24px, input text under 16px, any text under 16px, contrast under
4.5:1, and a disabled button that looks like an enabled one.

    cd wcma-calculator/tests/ux && npm install        # once
    bash wcma-calculator/tests/ux/run-audit.sh        # from the repo root

It seeds a throwaway SQLite database in a temp folder and serves the app with `php -S` on port
8170 (`UX_PORT` to change). Your real database is never touched. Needs a local `config.php`.
Add `class="no-audit"` to an element only when a rule genuinely doesn't apply to it.
```

- [ ] **Step 2: Create `run-audit.sh`**

```bash
#!/usr/bin/env bash
# wcma-calculator/tests/ux/run-audit.sh — phone audit (mobile UX spec 2026-09-28 §B4).
# Seeds a throwaway database, serves the app, runs audit.mjs, cleans up.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$(cd "$HERE/../.." && pwd)"
PORT="${UX_PORT:-8170}"
TMP="$(mktemp -d)"
TMPW="$(cygpath -m "$TMP" 2>/dev/null || echo "$TMP")"   # PHP on Windows needs C:/… paths
cat > "$TMP/prepend.php" <<EOF
<?php
if (!defined('DB_PATH')) define('DB_PATH', '$TMPW/ux.db');
if (!defined('WCMA_MAIL_LOG')) define('WCMA_MAIL_LOG', '$TMPW/mail.log');
EOF
php -d auto_prepend_file="$TMPW/prepend.php" "$APP/seed-hub-db.php" > /dev/null
( cd "$APP" && exec php -d auto_prepend_file="$TMPW/prepend.php" -S "localhost:$PORT" > "$TMP/server.log" 2>&1 ) &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -rf "$TMP"' EXIT
for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://localhost:$PORT/index.php" && break; sleep 0.5; done
UX_BASE="http://localhost:$PORT" node "$HERE/audit.mjs"
```

- [ ] **Step 3: Create `audit.mjs`**

```js
// wcma-calculator/tests/ux/audit.mjs — phone audit (mobile UX spec 2026-09-28 §B4). Run via run-audit.sh.
import { chromium } from 'playwright';

const BASE = process.env.UX_BASE || 'http://localhost:8170';
const RULES = { tap: 44, check: 24, inputFont: 16, text: 16, contrast: 4.5 };

/** Runs inside the page. Returns one string per problem. */
function auditInPage(R) {
  const problems = [];
  const visible = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0'; };
  const describe = el => `${el.tagName.toLowerCase()}${typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : ''} "${(el.innerText || el.value || el.getAttribute('aria-label') || el.name || '').trim().replace(/\s+/g, ' ').slice(0, 40)}"`;
  const skipped = el => el.closest('.hub-skip, .no-audit, [aria-hidden="true"], script, style, noscript');
  // A link inside a sentence is exempt from the tap-height rule; header, footer, sub-nav and row links are not.
  const inProse = a => {
    if (a.closest('.hub-account, .hub-footer, .hub-subnav, .hub-line')) return false;
    const p = a.parentElement;
    return getComputedStyle(a).display === 'inline' && ['P', 'LI', 'TD', 'DD', 'SPAN', 'STRONG', 'EM', 'SMALL', 'LABEL'].includes(p.tagName)
      && (p.innerText || '').trim().length > (a.innerText || '').trim().length + 3;
  };

  const over = document.documentElement.scrollWidth - innerWidth;
  if (over > 0) problems.push(`page scrolls sideways by ${over}px`);

  for (const el of document.querySelectorAll('a, button, input, select, textarea, summary, label.pretech-upload')) {
    if (!visible(el) || skipped(el)) continue;
    const type = (el.getAttribute('type') || '').toLowerCase();
    if (el.tagName === 'INPUT' && ['hidden', 'file'].includes(type)) continue;
    const r = el.getBoundingClientRect();
    if (el.tagName === 'INPUT' && (type === 'checkbox' || type === 'radio')) {
      if (r.width < R.check || r.height < R.check) problems.push(`${type} smaller than ${R.check}px: ${describe(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
      const row = el.closest('label');
      if (row && row.getBoundingClientRect().height < R.tap) problems.push(`${type} label row shorter than ${R.tap}px: ${describe(row)}`);
      continue;
    }
    if (el.tagName === 'A' && inProse(el)) continue;
    if (r.height < R.tap) problems.push(`tap target shorter than ${R.tap}px: ${describe(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
    if (/INPUT|SELECT|TEXTAREA/.test(el.tagName) && parseFloat(getComputedStyle(el).fontSize) < R.inputFont)
      problems.push(`input text under ${R.inputFont}px: ${describe(el)}`);
  }

  // A disabled button must not look like an enabled one: its border must be dashed.
  for (const el of document.querySelectorAll('button:disabled, .hub-btn[aria-disabled="true"]')) {
    if (visible(el) && !skipped(el) && getComputedStyle(el).borderTopStyle !== 'dashed') problems.push(`disabled button looks enabled: ${describe(el)}`);
  }
  // An enabled button must not be grey (the legacy #95a5a6 look).
  for (const el of document.querySelectorAll('button:not(:disabled), a.btn, a.hub-btn, label.pretech-upload')) {
    if (!visible(el) || skipped(el)) continue;
    if (getComputedStyle(el).backgroundColor.replace(/\s/g, '') === 'rgb(149,165,166)') problems.push(`enabled button is grey: ${describe(el)}`);
  }

  const lum = c => { const v = c.map(x => { x /= 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
  const rgba = s => { const m = s.match(/[\d.]+/g) || []; return m.map(Number); };
  const bgOf = el => { for (let e = el; e; e = e.parentElement) { const c = rgba(getComputedStyle(e).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0.5)) return c.slice(0, 3); } return [255, 255, 255]; };
  const seen = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const el = node.parentElement;
    if (!node.textContent.trim() || !el || seen.has(el) || !visible(el) || skipped(el) || el.closest('select')) continue;
    seen.add(el);
    const s = getComputedStyle(el);
    const size = parseFloat(s.fontSize);
    if (size < R.text) problems.push(`text under ${R.text}px (${size}px): ${describe(el)}`);
    const [a, b] = [lum(rgba(s.color).slice(0, 3)), lum(bgOf(el))].sort((x, y) => y - x);
    const ratio = (a + 0.05) / (b + 0.05);
    if (ratio < R.contrast) problems.push(`contrast ${ratio.toFixed(2)}:1 (${s.color} on rgb(${bgOf(el).join(',')})): ${describe(el)}`);
  }
  return [...new Set(problems)];
}

let failed = 0;
async function audit(page, name) {
  await page.waitForLoadState('networkidle');
  for (const [label, scale] of [[name, 1], [name + ' @150%', 1.5]]) {
    const style = scale === 1 ? null : await page.addStyleTag({ content: `html { font-size: ${scale * 100}% !important; } body.hub { font-size: ${18 * scale}px !important; }` });
    const problems = await page.evaluate(auditInPage, RULES);
    if (style) await style.evaluate(n => n.remove());
    if (problems.length) failed++;
    console.log(`${problems.length ? 'FAIL' : 'ok  '} ${label}${problems.length ? '\n  - ' + problems.join('\n  - ') : ''}`);
  }
}

const browser = await chromium.launch();
try {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const go = async sel => Promise.all([page.waitForNavigation(), page.click(sel)]);

  await page.goto(BASE + '/index.php'); await audit(page, 'landing');
  await page.goto(BASE + '/auth.php?action=login'); await audit(page, 'sign in');
  await page.goto(BASE + '/auth.php?action=register'); await audit(page, 'create account');
  await page.fill('#name', 'Pat Winters');
  await page.fill('#email', `pat${Date.now()}@example.com`);
  await page.fill('input[name=password]', 'password123');
  await page.fill('input[name=password_confirm]', 'password123');
  await go('button[type=submit]');
  await audit(page, 'home');

  await go('section.hub-event:has-text("NASCC") a:has-text("Add a car for this event")');
  await audit(page, 'add a car');
  await page.fill('#car-car_number', '42');
  await page.fill('#car-make', 'Honda');
  await page.fill('#car-model', 'Civic');
  await page.fill('#car-colour', 'Blue');
  await go('button:has-text("Add car")');

  await page.selectOption('select[name=class]', 'SS');
  await page.waitForTimeout(300);
  await audit(page, 'ice tech sheet');
  await page.fill('input[name=car_weight]', '2700');
  await page.fill('input[name=engine_hp]', '140');
  for (const h of await page.locator('.checklist-section-header').all()) await h.click();
  for (const b of await page.locator('button:text-is("OK"), button:text-is("Confirm")').all()) if (await b.isVisible()) await b.click();
  const ratings = page.locator('input[placeholder^="Rating"]');
  for (let i = 0; i < await ratings.count(); i++) await ratings.nth(i).fill(i ? 'SFI 3.2A/1' : 'SA2020');
  await page.check('input[name=log_book_turned_in][value="1"]');
  await page.evaluate(() => {
    for (const cv of document.querySelectorAll('canvas')) {
      if (!cv.getBoundingClientRect().width) continue;
      const r = cv.getBoundingClientRect();
      const ev = (t, x, y) => cv.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: r.left + x, clientY: r.top + y, pointerId: 1, pointerType: 'touch', isPrimary: true, buttons: 1 }));
      ev('pointerdown', 20, 40); for (let i = 1; i <= 10; i++) ev('pointermove', 20 + i * 15, 40 + i * 5); ev('pointerup', 170, 90);
    }
  });
  await go('button[type=submit]');
  await audit(page, 'submitted sheet');

  const carUrl = await page.locator('.hub-subnav a').first().getAttribute('href');
  await go('a:has-text("Pre-tech with photos")');
  await audit(page, 'pre-tech photos');
  await page.goto(BASE + '/' + carUrl);
  await audit(page, 'car page');
} finally {
  await browser.close();
}
console.log(failed ? `\n${failed} page check(s) failed` : '\nAll pages pass');
process.exit(failed ? 1 : 0);
```

- [ ] **Step 4: Install and run it — it must fail**

```bash
cd wcma-calculator/tests/ux && npm install && cd ../../..
bash wcma-calculator/tests/ux/run-audit.sh > .superpowers/sdd/2026-09-28-ux-phase-b-style-pass/baseline-audit.txt; echo "exit $?"
```

Expected: `exit 1`. The baseline must include at least these known problems (from the 2026-09-28 review): `input text under` or `tap target shorter` on sign-in/create-account inputs (35px tall); `button.hub-menu-btn "Menu"` text 13.3px; `a "Profile"`/`a "Sign out"` shorter than 44px; `enabled button is grey` on pre-tech "Take or choose photo"; `radio smaller than 24px` on the log book; `contrast` on `.badge-pending` "Photo needed"; text under 16px on `.checklist-mark-all` and `.gear-chip`. If the script itself errors (a selector not found), fix the script, not the app — the flow is known to work (Phase A walk). Record the baseline problem count in the ledger.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/tests/ux
git commit -m "test(ux): phone audit for the member flow (fails on today's pages)"
```

---

### Task 2: One button language (§B1)

**Files:**
- Modify: `wcma-calculator/css/hub.css` (append the Phase B section)

- [ ] **Step 1: Confirm the button failures in the baseline**

Run: `grep -E "grey|disabled button|CREATE ACCOUNT|SUBMIT|contrast .*btn" .superpowers/sdd/2026-09-28-ux-phase-b-style-pass/baseline-audit.txt`
Expected: lines for the grey pre-tech/secondary buttons and the disabled pre-tech submit.

- [ ] **Step 2: Append to `css/hub.css`**

```css
/* ── Phase B: shared style pass (mobile UX spec 2026-09-28 §B) ── */

/* B1. One button language. Legacy .btn classes (auth, tech sheet, pre-tech, gear, admin) become hub buttons. */
body.hub .btn,
body.hub button[type="submit"]:not(.hub-btn):not(.link-button):not(.checklist-chip) {
  min-height: 48px; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 0 20px; border-radius: 8px; border: 2px solid var(--hub-red);
  background: var(--hub-red); color: #fff; font-family: inherit; font-size: 17px; font-weight: 700;
  text-transform: none; letter-spacing: 0; text-decoration: none; transform: none; box-shadow: none;
}
body.hub .btn:hover, body.hub .btn-primary:hover { background: var(--hub-red-ink); border-color: var(--hub-red-ink); transform: none; box-shadow: none; }
body.hub .btn-secondary, body.hub .pretech-upload { background: #fff; color: var(--hub-ink); border-color: var(--hub-ink); }
body.hub .btn-secondary:hover, body.hub .pretech-upload:hover { background: var(--hub-paper); color: var(--hub-ink); border-color: var(--hub-ink); }
body.hub .hub-btn { text-transform: none; }

/* Disabled: dashed outline, muted text. Never grey-filled, so grey always means "not yet". */
body.hub .btn:disabled, body.hub .hub-btn:disabled, body.hub button:disabled, body.hub .hub-btn[aria-disabled="true"] {
  background: var(--hub-card); color: var(--hub-ink-2); border: 2px dashed var(--hub-ink-2); cursor: not-allowed;
}

/* Link-style buttons (signature Clear, remove driver) */
body.hub .link-button {
  display: inline-flex; align-items: center; min-height: 44px; padding: 0 8px;
  font-size: 16px; color: var(--hub-red-ink); text-decoration: underline;
}
```

- [ ] **Step 3: Re-run the audit**

Run: `bash wcma-calculator/tests/ux/run-audit.sh | grep -cE "grey|disabled button looks enabled"`
Expected: `0`. (Other failures remain; Tasks 3–4 handle them.)

- [ ] **Step 4: PHPUnit and commit**

Run: `cd wcma-calculator && php phpunit.phar` — expected all green.

```bash
git add wcma-calculator/css/hub.css
git commit -m "feat(ux): one button language — legacy buttons become hub buttons, disabled is dashed"
```

---

### Task 3: Tap targets and text sizes (§B2)

**Files:**
- Modify: `wcma-calculator/css/hub.css` (Phase B section)

- [ ] **Step 1: Count the size failures**

Run: `bash wcma-calculator/tests/ux/run-audit.sh | grep -E "shorter than|smaller than|under 16px" | sort | uniq -c | sort -rn | head -30`
Expected: a non-zero list (inputs, radios, header links, Menu, Clear, password toggle, small text).

- [ ] **Step 2: Append to the Phase B section of `css/hub.css`**

```css
/* B2. Tap targets and text. Baselines use :where() so any component rule still wins. */
:where(body.hub) button, :where(body.hub) input, :where(body.hub) select, :where(body.hub) textarea { font-family: inherit; font-size: 16px; }
body.hub input[type="text"], body.hub input[type="email"], body.hub input[type="number"], body.hub input[type="password"],
body.hub input[type="tel"], body.hub input[type="search"], body.hub input[type="date"], body.hub select {
  min-height: 48px; font-size: 17px; padding: 10px 12px;
}
body.hub textarea { min-height: 96px; font-size: 17px; }

/* Radios and checkboxes: 24px, and the whole label row is the tap target. */
body.hub input[type="radio"], body.hub input[type="checkbox"] { width: 24px !important; height: 24px !important; margin: 0; flex-shrink: 0; accent-color: var(--hub-red); }
:where(body.hub) label:has(> input[type="radio"]), :where(body.hub) label:has(> input[type="checkbox"]) {
  display: flex; align-items: center; gap: 10px; min-height: 44px; cursor: pointer;
}

/* Password show/hide: a 44px button inside the field. */
body.hub .password-toggle { min-width: 44px; min-height: 44px; justify-content: center; right: 2px; }
body.hub .password-field input[type="password"], body.hub .password-field input[type="text"] { padding-right: 52px; }

/* Header, sub-nav, footer and row links: 44px tall. */
body.hub .hub-account a, body.hub .hub-subnav a, body.hub .hub-footer a, body.hub .hub-line > a {
  display: inline-flex; align-items: center; min-height: 44px;
}
body.hub .hub-account a { padding: 0 4px; }
body.hub .hub-menu-btn { font-size: 16px; padding: 0 14px; color: var(--hub-ink); }
body.hub .hub-nav a { min-height: 44px; }

/* No member-facing text under 16px. */
body.hub .checklist-mark-all, body.hub .gear-chip, body.hub .pretech-status, body.hub .pretech-typed label,
body.hub .auth-links, body.hub .checklist-progress-label, body.hub .hub-pill, body.hub .hub-class,
body.hub .form-hint, body.hub small, body.hub .checklist-section-count { font-size: 16px; }
body.hub .checklist-mark-all { min-height: 44px; padding: 0 8px; }
```

- [ ] **Step 3: Re-run the audit and check the season cards**

Run: `bash wcma-calculator/tests/ux/run-audit.sh | grep -E "shorter than|smaller than|under 16px"`
Expected: no lines except any on the `submitted sheet` page's printed-form text (Task 4 handles `.sheet-doc`). For any other remaining line, add the smallest targeted rule to this section and ledger it as a ruling.

Then screenshot Add a car with the throwaway server from Phase A (`scratch/ux/step.js … garage.php?action=add` then `clips.js`) and check the Ice/Summer/Both cards are still 56px (72px stacked under 420px) — Review Focus 5. If the global label rule shrank them, raise the specificity of `.garage-season-options label` rather than weakening the global rule.

- [ ] **Step 4: PHPUnit and commit**

Run: `cd wcma-calculator && php phpunit.phar` — expected all green.

```bash
git add wcma-calculator/css/hub.css
git commit -m "feat(ux): 44px tap targets, 24px radios, 48px inputs and a 16px text floor"
```

---

### Task 4: Contrast and status chips (§B3)

**Files:**
- Modify: `wcma-calculator/css/hub.css` (Phase B section)
- Modify: `wcma-calculator/tech-sheets.php` (`handleView()`: wrap the rendered sheet)
- Test: `wcma-calculator/tests/TechSheetViewSourceTest.php`

- [ ] **Step 1: Write the failing source test**

Add to `tests/TechSheetViewSourceTest.php`:

```php
    public function testRenderedSheetIsWrappedForScreenSizing(): void
    {
        $this->assertStringContainsString('<div class="sheet-doc"><?= renderTechSheetHtml(', $this->viewBody());
    }
```

Run: `php phpunit.phar --filter testRenderedSheetIsWrappedForScreenSizing` — expected FAIL.

- [ ] **Step 2: Wrap the rendered sheet**

In `tech-sheets.php` `handleView()`, change

```php
  <?= renderTechSheetHtml($sheet, $drivers, $event ?? [], techSheetSignatureResolverWeb((int)$sheet['id']), 'assets/wcma-logo.png') ?>
```

to

```php
  <div class="sheet-doc"><?= renderTechSheetHtml($sheet, $drivers, $event ?? [], techSheetSignatureResolverWeb((int)$sheet['id']), 'assets/wcma-logo.png') ?></div>
```

Run the test again — expected PASS.

- [ ] **Step 3: Append to the Phase B section of `css/hub.css`**

```css
/* B3. Contrast. Status words are chips: dark ink on a tint. */
body.hub .hub-status { padding: 2px 10px 2px 8px; border-radius: 999px; }
body.hub .hub-status--ok { background: var(--hub-ok-bg); color: var(--hub-ok-ink); }
body.hub .hub-status--warn { background: var(--hub-warn-bg); color: var(--hub-warn-ink); }
body.hub .hub-status--todo { background: var(--hub-todo-bg); color: var(--hub-red-ink); }
body.hub .hub-status--info { background: var(--hub-paper); color: var(--hub-ink-2); }

/* Legacy badges (pre-tech "Photo needed", car status on older pages) as the same chips. */
body.hub .badge-ok, body.hub .badge-fail, body.hub .badge-pending {
  display: inline-block; padding: 2px 10px; border-radius: 999px; font-weight: 700; font-size: 16px;
}
body.hub .badge-ok { color: var(--hub-ok-ink); background: var(--hub-ok-bg); }
body.hub .badge-fail { color: var(--hub-red-ink); background: var(--hub-todo-bg); }
body.hub .badge-pending { color: var(--hub-warn-ink); background: var(--hub-warn-bg); }

/* Checklist OK / N/A and gear Confirm: outlined until chosen, then solid with a tick. */
body.hub .checklist-chip {
  min-height: 44px; min-width: 64px; padding: 0 14px; border-radius: 8px;
  border: 2px solid var(--hub-ink); background: #fff; color: var(--hub-ink); font-size: 16px; font-weight: 700;
}
body.hub .checklist-chip-selected-ok { background: var(--hub-ok-ink); border-color: var(--hub-ok-ink); color: #fff; }
body.hub .checklist-chip-selected-ok::before { content: "\2713\00a0"; }
body.hub .checklist-chip-selected-na { background: var(--hub-ink-2); border-color: var(--hub-ink-2); color: #fff; }
body.hub .checklist-section-count, body.hub .checklist-chevron, body.hub .checklist-progress-label { color: var(--hub-ink-2); }

/* The rendered tech sheet keeps its print sizes; on screen its small print is 16px. */
@media screen {
  body.hub .sheet-doc [style*="font-size:0.8"] { font-size: 16px !important; }
}
```

- [ ] **Step 4: Re-run the audit**

Run: `bash wcma-calculator/tests/ux/run-audit.sh | grep -E "contrast|under 16px"`
Expected: no lines. For any remaining line, add the smallest targeted rule (use an existing token) and ledger it.

- [ ] **Step 5: PHPUnit and commit**

Run: `cd wcma-calculator && php phpunit.phar` — expected all green.

```bash
git add wcma-calculator/css/hub.css wcma-calculator/tech-sheets.php wcma-calculator/tests/TechSheetViewSourceTest.php
git commit -m "feat(ux): status words as readable chips, outlined checklist choices with ticks"
```

---

### Task 5: Audit green, and a look at pages the audit doesn't visit

**Files:**
- Modify: `wcma-calculator/css/hub.css` only if the steps below find problems

- [ ] **Step 1: The audit passes**

Run: `bash wcma-calculator/tests/ux/run-audit.sh; echo "exit $?"`
Expected: every line `ok  …`, then `All pages pass`, `exit 0`. Fix any remaining failure with a targeted rule in the Phase B section, ledgered.

- [ ] **Step 2: Screenshots of pages outside the audit (Review Focus 1–3)**

With the Phase A throwaway server (`scratch/ux/`, port 8160, freshly seeded as in the Phase A plan's Task 7), take 375px screenshots with `scratch/ux/step.js` / `clips.js` of: `calculator.php` (signed out), `gear.php` for the signed-in user, `drivers.php`, `profile.php`, and, signed in as `matt.sinfield@gmail.com` / `password123`, `admin.php` and `inspect.php`. Check that buttons look like buttons (red or outlined, sentence case), nothing is grey unless disabled, and nothing overflows. Then open the submitted sheet and use the browser's print preview (`page.emulateMedia({ media: 'print' })` + screenshot) to confirm the printed sheet's small print is unchanged (Review Focus 3).

- [ ] **Step 3: PHPUnit, commit if anything changed**

Run: `cd wcma-calculator && php phpunit.phar` — expected all green.

```bash
git add wcma-calculator/css/hub.css
git commit -m "fix(ux): style pass follow-ups found on pages outside the audit"
```

---

## Self-review notes

- §B1 → Task 2; §B2 → Task 3; §B3 → Task 4; §B4 → Task 1 (the audit), run to green in Task 5.
- §B1 "a visible reason line beneath" disabled buttons: the only member-facing disabled button is pre-tech submit, which already has "Add every required photo to enable submitting." No other markup needed.
- The Phase A "hero links white" rule already exists (Phase A Task 5); the audit's contrast check confirms it on `landing`.
