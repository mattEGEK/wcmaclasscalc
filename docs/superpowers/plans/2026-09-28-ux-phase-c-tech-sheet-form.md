# UX Phase C — Tech Sheet Form Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The tech sheet form is forgiving on a phone: it keeps answers through interruptions, never shows empty or broken-looking boxes, says in words what is missing and takes you to it, asks for one signature when you drive your own car, and the submitted sheet reads naturally.

**Architecture:** Both tech sheet forms (summer in `tech-sheets.php`, ice in `ice-sheet-page.php`) share `js/tech-sheet-form.js`, `js/tech-sheet-checklist.js` and `js/signature-pad.js`, so each change lands once in the shared JS plus small markup edits in both forms. New browser logic comes as small modules with pure, node-tested cores (`js/tech-sheet-draft.js`, `js/form-problems.js`, `isSelfChoice` in `js/driver-choice.js`). Server contracts do not change: the one-signature case copies the pad into both hidden fields on the client. The Phase B phone audit gains checks for each behaviour.

**Tech Stack:** Vanilla JS (IIFE modules, `module.exports` for node tests), PHP 8.3, CSS, PHPUnit (`php phpunit.phar`), node tests (`node --test "tests/js/*.test.js"`), Playwright phone audit (`bash wcma-calculator/tests/ux/run-audit.sh`).

**Spec:** `docs/superpowers/specs/2026-09-28-mobile-ux-older-users-design.md` (Phase C, §C1–C5)

## Global Constraints

- Drafts: `localStorage`, key `wcma-tsdraft:<userId>:<carId>:<eventId>`, new sheets only (not edits), signatures and files never saved, older than 14 days ignored and deleted, cleared when the sheet is viewed after submit. Every storage access in try/catch; without storage the form works as today.
- Notice copy: "We kept your answers from earlier." with a **Start over** button (≥ 44px).
- Empty checklist copy: "Choose your class above and its checklist appears here."
- Required fields say "(required)" in the label text, optional ones "(optional)". No asterisks.
- One signature: when the chosen Driver 1 is the signed-in user's own profile, one pad labelled "Your signature (entrant and driver)"; the client fills both hidden signature fields. Server validation unchanged.
- Signature error sits above the pads and names the box, e.g. "Please sign in the Driver's signature box."
- Ice season display: "Winter 2026–27" for stored season 2027 (en dash). Stored `season` unchanged.
- Engine line shows only filled parts: "140 HP", "1998 cc", "1998 cc / 140 HP".
- Work on branch `ux-phase-c` from `main`. Run PHPUnit from `wcma-calculator/`: `php phpunit.phar` (baseline 939). JS: `node --test "tests/js/*.test.js"` from `wcma-calculator/`. Audit from the repo root.
- Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_013Ka2ckHFvimiiH8JfC7tX5
  ```

## Review Focus

1. A draft saved for one car/event must never appear on another car's or event's sheet, or on an edit of a submitted sheet. Pinned by the draft-key tests in Task 4.
2. Private browsing / storage disabled: the form must still submit normally. Pinned in Task 4 by the node test that `hasStorage` returns null when `setItem` throws, and the browser glue returning early.
3. Switching Driver 1 from "you" to a co-driver after signing once must bring the driver pad back and require it. Pinned by the audit step in Task 3.
4. Editing an existing sheet whose driver is "you": leaving the single pad blank keeps both signatures on file. Pinned by Task 3's rule that a blank single pad leaves both hidden fields blank.
5. A restored draft with a class chosen must render that class's checklist with its answers (the ice form re-renders the checklist on class change). Pinned by the audit reload step in Task 4.

---

### Task 1: No empty or broken-looking boxes (§C2)

**Files:**
- Modify: `wcma-calculator/js/tech-sheet-checklist.js` (`render()`)
- Modify: `wcma-calculator/css/hub.css` (Phase B section end)
- Modify: `wcma-calculator/tests/ux/audit.mjs`

- [ ] **Step 1: Add the failing audit checks**

In `audit.mjs`, add inside `auditInPage` (before the `lum` helper):

```js
  // Empty message boxes and "0 of 0" progress read as broken (spec §C2).
  for (const el of document.querySelectorAll('.form-messages')) {
    if (visible(el) && !skipped(el) && !(el.innerText || '').trim()) problems.push(`empty message box is showing: ${describe(el)}`);
  }
  if (/\b0 of 0 items\b/.test(document.body.innerText)) problems.push('checklist shows "0 of 0 items"');
```

and in the flow, right after `await go('button:has-text("Add car")');` and before `selectOption`, add:

```js
  await audit(page, 'ice tech sheet (no class yet)');
```

- [ ] **Step 2: Run the audit — it must fail**

Run: `bash wcma-calculator/tests/ux/run-audit.sh | grep -A3 "no class yet"`
Expected: `FAIL ice tech sheet (no class yet)` with `empty message box is showing: div.form-messages.error` and `checklist shows "0 of 0 items"`.

- [ ] **Step 3: Implement**

`css/hub.css`, append:

```css
/* ── Phase C: tech sheet form (mobile UX spec 2026-09-28 §C) ── */
/* A message box with the hidden attribute stays hidden (the legacy .form-messages.error rule shows it). */
body.hub .form-messages[hidden] { display: none !important; }
```

`js/tech-sheet-checklist.js`, at the start of `render(container, sections, initialState)`:

```js
        if (!sections || Object.keys(sections).length === 0) {
            const empty = document.createElement('p');
            empty.className = 'form-hint checklist-empty';
            empty.textContent = 'Choose your class above and its checklist appears here.';
            container.appendChild(empty);
            return {
                getState: function () { return {}; }, isComplete: function () { return false; },
                highlightIncomplete: function () {}, clearHighlights: function () {},
            };
        }
```

Check the object `render()` normally returns and make the empty one expose the same method names (read the `return {` at the end of `render()`; add any other methods it has as no-ops).

- [ ] **Step 4: Audit and suites**

Run: `bash wcma-calculator/tests/ux/run-audit.sh; echo "exit $?"` — expected `All pages pass`, `exit 0`.
Run: `cd wcma-calculator && php phpunit.phar && node --test "tests/js/*.test.js"` — expected all green.

- [ ] **Step 5: Commit**

```bash
git add wcma-calculator/js/tech-sheet-checklist.js wcma-calculator/css/hub.css wcma-calculator/tests/ux/audit.mjs
git commit -m "feat(ux): tech sheet shows no empty error box and explains the checklist before a class is chosen"
```

---

### Task 2: Required fields, and problems shown where they are (§C3)

**Files:**
- Create: `wcma-calculator/js/form-problems.js`, `wcma-calculator/tests/js/form-problems.test.js`
- Modify: `wcma-calculator/ice-sheet-page.php`, `wcma-calculator/tech-sheets.php` (summer form markup and its `<script>` list), `wcma-calculator/js/tech-sheet-form.js`, `wcma-calculator/css/hub.css`
- Modify: `wcma-calculator/tests/IceSheetPageTest.php`, `wcma-calculator/tests/ux/audit.mjs`

**Interfaces:**
- Produces: `WcmaFormProblems` (browser global, also `module.exports`):
  - `messageFor(field: {dataMessage?: string, kind: 'text'|'select'|'radio', label: string}): string` — pure.
  - `show(target: Element, message: string): void` — adds `<p class="field-message" role="alert">` after `target` (after its `.sig-pad-actions`/radio group when given the group), scrolls it to the middle of the screen, focuses it (adds `tabindex="-1"` when not focusable).
  - `clearAll(form: Element): void` — removes every `.field-message`.
  - `wire(form: Element): void` — on `invalid` (capture) for required fields: `preventDefault()` the browser bubble, `show()` the field's message, and for the first invalid field only, scroll/focus.

- [ ] **Step 1: Write the failing node test**

`tests/js/form-problems.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { messageFor } = require('../../js/form-problems.js');

test('a field with data-message says exactly that', () => {
    assert.strictEqual(messageFor({ dataMessage: 'Enter the race weight.', kind: 'text', label: 'Race weight' }), 'Enter the race weight.');
});

test('without data-message: text says Enter, select and radio say Choose, label lower-cased without (required)', () => {
    assert.strictEqual(messageFor({ kind: 'text', label: 'Entrant (required)' }), 'Enter the entrant.');
    assert.strictEqual(messageFor({ kind: 'select', label: 'NASCC class (required)' }), 'Choose the NASCC class.');
    assert.strictEqual(messageFor({ kind: 'radio', label: 'Log book turned in? (required)' }), 'Choose an answer for "Log book turned in?".');
    assert.strictEqual(messageFor({ kind: 'text', label: '' }), 'Please fill this in.');
});
```

Run: `cd wcma-calculator && node --test tests/js/form-problems.test.js` — expected FAIL (`Cannot find module`).

- [ ] **Step 2: Create `js/form-problems.js`**

```js
// wcma-calculator/js/form-problems.js
// Says in words what is missing, next to the field, and takes you to it (mobile UX spec 2026-09-28 §C3).
(function (root) {
    'use strict';

    function cleanLabel(label) {
        return String(label || '').replace(/\(required\)|\(optional\)/gi, '').replace(/\s+/g, ' ').trim();
    }

    /** The words for a missing field. Keeps acronyms like NASCC; lower-cases a leading capital word. */
    function messageFor(field) {
        if (field.dataMessage) return field.dataMessage;
        const label = cleanLabel(field.label);
        if (!label) return 'Please fill this in.';
        if (field.kind === 'radio') return 'Choose an answer for "' + label + '".';
        const words = label.split(' ');
        if (/^[A-Z][a-z]/.test(words[0])) words[0] = words[0].toLowerCase();
        return (field.kind === 'select' ? 'Choose the ' : 'Enter the ') + words.join(' ') + '.';
    }

    const api = { messageFor: messageFor };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }

    function labelText(el) {
        if (el.type === 'radio') {
            const group = el.closest('[data-radio-group]');
            return group ? group.getAttribute('data-radio-group') : '';
        }
        const lab = el.id ? root.document.querySelector('label[for="' + el.id + '"]') : null;
        return lab ? lab.textContent : '';
    }

    function show(target, message) {
        const msg = root.document.createElement('p');
        msg.className = 'field-message';
        msg.setAttribute('role', 'alert');
        msg.textContent = message;
        target.insertAdjacentElement('afterend', msg);
    }

    function focusOn(el) {
        if (!el.matches('input, select, textarea, button, [tabindex]')) el.setAttribute('tabindex', '-1');
        el.scrollIntoView({ block: 'center' });
        el.focus({ preventScroll: true });
    }

    function clearAll(form) {
        form.querySelectorAll('.field-message').forEach(function (m) { m.remove(); });
    }

    function wire(form) {
        let first = true;
        form.addEventListener('invalid', function (e) {
            const el = e.target;
            e.preventDefault();
            const group = el.type === 'radio' ? el.closest('[data-radio-group]') : null;
            const anchor = group || el;
            if (anchor.nextElementSibling && anchor.nextElementSibling.classList.contains('field-message')) return;
            show(anchor, messageFor({
                dataMessage: el.getAttribute('data-message') || (group && group.getAttribute('data-message')),
                kind: el.tagName === 'SELECT' ? 'select' : (el.type === 'radio' ? 'radio' : 'text'),
                label: labelText(el),
            }));
            if (first) { first = false; focusOn(el); setTimeout(function () { first = true; }, 0); }
        }, true);
        form.addEventListener('input', function (e) {
            const next = e.target.nextElementSibling;
            if (next && next.classList.contains('field-message')) next.remove();
        });
    }

    root.WcmaFormProblems = { messageFor: messageFor, show: function (target, message) { show(target, message); focusOn(target); }, clearAll: clearAll, wire: wire };
})(typeof window !== 'undefined' ? window : globalThis);
```

Run the node test — expected PASS.

- [ ] **Step 3: Write the failing PHP test for the ice form labels**

Add to `tests/IceSheetPageTest.php` (use the file's existing vm/render helper — read the file first; if it builds the vm via `iceSheetFormVm(...)`, reuse that call):

```php
    public function testRequiredAndOptionalFieldsSaySoAndCarryAMessage(): void
    {
        $html = $this->renderNewSheet();   // the file's helper that renders a new (not edit) ice sheet
        $this->assertStringContainsString('<label for="car_weight">Race weight in lbs, without driver (required)</label>', $html);
        $this->assertStringContainsString('data-message="Enter the race weight."', $html);
        $this->assertMatchesRegularExpression('/<label for="ice_class">[A-Z]+ class \(required\)<\/label>/', $html);
        $this->assertStringContainsString('data-message="Choose your class."', $html);
        $this->assertStringContainsString('<label for="entrant_name">Entrant (required)</label>', $html);
        $this->assertStringContainsString('<label for="driver1_choice">Driver (required)</label>', $html);
        $this->assertStringContainsString('<label for="engine_hp">Engine HP (optional)</label>', $html);
        $this->assertStringContainsString('data-radio-group="Log book turned in? (required)"', $html);
        $this->assertStringContainsString('data-message="Choose Yes or No for the log book."', $html);
        $this->assertStringContainsString('<script src="js/form-problems.js"></script>', $html);
    }
```

If the file has no such helper, add a private `renderNewSheet(): string` that builds the same vm its other new-sheet tests build. Run it — expected FAIL.

- [ ] **Step 4: Change the ice form markup (`ice-sheet-page.php`)**

- Race weight: `<label for="car_weight">Race weight in lbs, without driver (required)</label>` and add `data-message="Enter the race weight."` to the input.
- Car colour (when shown): label `Car colour (required)`, `data-message="Enter the car's colour."`.
- Class: `<label for="ice_class">' . h($vm['club']) . ' class (required)</label>`, `data-message="Choose your class."` on the select.
- Entrant: `Entrant (required)`, `data-message="Enter the entrant's name."`.
- Driver: `Driver (required)`.
- Engine HP: `Engine HP (optional)`.
- Log book: replace the two labels' container with
  `'<div class="detail-card"><h2>Log Book</h2><div class="radio-group" data-radio-group="Log book turned in? (required)" data-message="Choose Yes or No for the log book."><p class="radio-group-label">Log book turned in? (required)</p>'` … the two existing `<label class="checkbox-label">` lines … `'</div></div>'`.
- Script list: add `<script src="js/form-problems.js"></script>` before `js/tech-sheet-form.js`.

Run the PHP test — expected PASS.

- [ ] **Step 5: The same on the summer form (`tech-sheets.php`)**

In the new/edit form template: `Event (required)` with `data-message="Choose the event."`; `Car colour (required)`; `Entrant (required)` + `data-message="Enter the entrant's name."`; `Driver name (Driver 1) (required)`; `Engine HP (optional)`; the log book radios wrapped in the same `radio-group` div as the ice form; `<script src="js/form-problems.js"></script>` before `js/tech-sheet-form.js`. Add a source test to `tests/TechSheetViewSourceTest.php`:

```php
    public function testSummerFormLoadsFormProblemsAndMarksRequiredFields(): void
    {
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString('<script src="js/form-problems.js"></script>', $src);
        $this->assertStringContainsString('<label for="entrant_name">Entrant (required)</label>', $src);
        $this->assertStringContainsString('data-radio-group="Log book turned in? (required)"', $src);
    }
```

- [ ] **Step 6: Use it in `js/tech-sheet-form.js`**

Near the top (after the checklist widget): `const form = document.getElementById('tech-sheet-form'); WcmaFormProblems.wire(form);`

In the submit handler, after `clearAllHighlights();` add `WcmaFormProblems.clearAll(form);`. Then at each existing failure branch, after setting `errorEl.textContent`, call `WcmaFormProblems.show(<the first highlighted element>, <the same message>)`:
- checklist incomplete → the first `.checklist-item-row.field-error` (query after `highlightIncomplete()`), falling back to `#checklist-container`.
- driver 1 choice → the element that got `.error`.
- driver 1 equipment → the first `#equipment-container .field-error`.
- endurance driver / duplicate → the element that got `.error`.
- signatures → handled in Task 3; leave the existing branch as is for now.

Also move the error box: in both forms, move `<div id="tech-sheet-error" class="form-messages error" hidden></div>` to directly **above** `<div class="form-actions">` so it sits just above the submit button, where the member is looking.

- [ ] **Step 7: CSS** — append to the Phase C section:

```css
body.hub .field-message { color: var(--hub-red-ink); background: var(--hub-todo-bg); font-weight: 700; font-size: 16px;
  padding: 8px 12px; border-radius: 8px; margin: 6px 0 12px; }
body.hub .radio-group-label { font-weight: 700; margin: 0 0 4px; }
```

- [ ] **Step 8: Audit check** — in `audit.mjs`, after `await audit(page, 'ice tech sheet');` (class chosen, nothing filled) add:

```js
  await page.click('#tech-sheet-submit-btn');
  await page.waitForTimeout(300);
  const firstProblem = await page.evaluate(() => ({
    message: (document.querySelector('.field-message') || {}).textContent || '',
    focused: document.activeElement && document.activeElement.id,
  }));
  report('submitting an empty sheet says what is missing', firstProblem.message === 'Enter the race weight.' && firstProblem.focused === 'car_weight'
    ? [] : [`expected "Enter the race weight." with focus on car_weight, got ${JSON.stringify(firstProblem)}`]);
  await audit(page, 'ice tech sheet with problems shown');
```

Run the audit — expected `All pages pass`. Run PHPUnit and node tests — expected green.

- [ ] **Step 9: Commit**

```bash
git add wcma-calculator/js/form-problems.js wcma-calculator/tests/js/form-problems.test.js wcma-calculator/ice-sheet-page.php wcma-calculator/tech-sheets.php wcma-calculator/js/tech-sheet-form.js wcma-calculator/css/hub.css wcma-calculator/tests/IceSheetPageTest.php wcma-calculator/tests/TechSheetViewSourceTest.php wcma-calculator/tests/ux/audit.mjs
git commit -m "feat(ux): tech sheet marks required fields and shows each problem next to its field"
```

---

### Task 3: One signature when you drive your own car (§C4)

**Files:**
- Modify: `wcma-calculator/js/driver-choice.js`, `wcma-calculator/tests/js/driver-choice.test.js`
- Modify: `wcma-calculator/ice-sheet-page.php`, `wcma-calculator/tech-sheets.php`, `wcma-calculator/js/tech-sheet-form.js`
- Modify: `wcma-calculator/tests/IceSheetPageTest.php`, `wcma-calculator/tests/ux/audit.mjs`

**Interfaces:**
- Produces: `isSelfChoice(drivers: {id, name, self}[], choice: string): boolean` exported from `driver-choice.js` (and on `WcmaDriverChoice`).
- Markup: `<label id="entrant-sig-label">Entrant's signature</label>`; driver pad wrapped in `<div id="driver-sig-block">…</div>` (its label, pad and Clear).

- [ ] **Step 1: Failing node test** — add to `tests/js/driver-choice.test.js` (and add `isSelfChoice` to the `require` destructuring):

```js
test('isSelfChoice is true only for the signed-in user\'s own profile', () => {
    assert.strictEqual(isSelfChoice(drivers, '5'), true);
    assert.strictEqual(isSelfChoice(drivers, 5), true);
    assert.strictEqual(isSelfChoice(drivers, '6'), false);
    assert.strictEqual(isSelfChoice(drivers, NEW), false);
    assert.strictEqual(isSelfChoice([], '5'), false);
});
```

Run: `node --test tests/js/driver-choice.test.js` — expected FAIL.

- [ ] **Step 2: Implement in `js/driver-choice.js`**

```js
    /** Whether Driver 1 is the signed-in user themself, so one signature covers entrant and driver. */
    function isSelfChoice(drivers, choice) {
        return (drivers || []).some(function (d) { return d.self && String(d.id) === String(choice); });
    }
```

Add `isSelfChoice` to the object the module exports and to the browser global (follow how `driverChoiceComplete` is exposed). Run the node test — expected PASS.

- [ ] **Step 3: Failing PHP test** — add to `tests/IceSheetPageTest.php`:

```php
    public function testSignaturePadsCanCollapseToOne(): void
    {
        $html = $this->renderNewSheet();
        $this->assertStringContainsString('<label id="entrant-sig-label">Entrant\'s signature</label>', $html);
        $this->assertMatchesRegularExpression('/<div id="driver-sig-block">\s*<label>Driver\'s signature<\/label>/', $html);
        $this->assertLessThan(strpos($html, 'id="entrant-sig-label"'), strpos($html, 'id="sig-error"'));
    }
```

Run it — expected FAIL.

- [ ] **Step 4: Markup in both forms**

Signatures card becomes (ice shown; the summer form keeps its `$hasEntrantSignature`/`$hasDriverSignature` image lines in the same places):

```php
        . '<p id="sig-error" class="field-message" role="alert" hidden></p>'
        . '<label id="entrant-sig-label">Entrant\'s signature</label><div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>'
        . '<div id="driver-sig-block"><label>Driver\'s signature</label><div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>'
        . '<div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div></div></div>';
```

Add `body.hub .field-message[hidden] { display: none; }` to the Phase C CSS section. Run the PHP test — expected PASS. Add the equivalent source assertions for `tech-sheets.php` to `TechSheetViewSourceTest.php` (`id="driver-sig-block"`, `id="entrant-sig-label"`, `id="sig-error"`).

- [ ] **Step 5: Behaviour in `js/tech-sheet-form.js`**

After `WcmaDriverChoice.wire(driver1Choice, driver1NewName);`:

```js
    // One signature when Driver 1 is the signed-in user (spec §C4): the pad counts as both.
    const driverSigBlock = document.getElementById('driver-sig-block');
    const entrantSigLabel = document.getElementById('entrant-sig-label');
    const sigError = document.getElementById('sig-error');
    function oneSigner() { return WcmaDriverChoice.isSelfChoice(window.TECH_SHEET_DRIVERS || [], driver1Choice.value); }
    function syncSigners() {
        const one = oneSigner();
        driverSigBlock.hidden = one;
        entrantSigLabel.textContent = one ? 'Your signature (entrant and driver)' : 'Entrant\'s signature';
        if (!one) driverPad.resize();   // the canvas had no size while hidden
    }
    driver1Choice.addEventListener('change', syncSigners);
    syncSigners();
```

Replace the signature branch of the submit handler with:

```js
        const one = oneSigner();
        const entrantSignatureMissing = entrantPad.isEmpty() && !window.TECH_SHEET_HAS_ENTRANT_SIGNATURE;
        const driverSignatureMissing = !one && driverPad.isEmpty() && !window.TECH_SHEET_HAS_DRIVER_SIGNATURE;
        sigError.hidden = true;
        if (entrantSignatureMissing || driverSignatureMissing) {
            e.preventDefault();
            if (entrantSignatureMissing) entrantSigWrap.classList.add('field-error');
            if (driverSignatureMissing) driverSigWrap.classList.add('field-error');
            const box = one ? 'the signature box' : (entrantSignatureMissing && driverSignatureMissing ? 'both signature boxes'
                : (entrantSignatureMissing ? 'the Entrant\'s signature box' : 'the Driver\'s signature box'));
            sigError.textContent = 'Please sign in ' + box + '.';
            sigError.hidden = false;
            errorEl.textContent = sigError.textContent;
            errorEl.hidden = false;
            errorEl.classList.add('show');
            sigError.scrollIntoView({ block: 'center' });
            return;
        }
```

and the two signature lines at the end with:

```js
        const entrantData = entrantPad.isEmpty() ? '' : entrantPad.toPNGDataURL();
        document.getElementById('entrant_signature').value = entrantData;
        // One signer: the same signature is the driver's. A blank pad leaves both blank, keeping signatures on file.
        document.getElementById('driver_signature').value = one ? entrantData : (driverPad.isEmpty() ? '' : driverPad.toPNGDataURL());
```

Also clear `sigError.hidden = true` in `clearAllHighlights()`.

- [ ] **Step 6: Audit checks** — in `audit.mjs`, on the ice sheet after the class is chosen, add:

```js
  const pads = async () => page.evaluate(() => [...document.querySelectorAll('canvas')].filter(c => c.getBoundingClientRect().width > 0).length);
  report('one signature pad when you are the driver', (await pads()) === 1 ? [] : [`expected 1 visible pad, saw ${await pads()}`]);
  await page.selectOption('#driver1_choice', 'new');
  report('two signature pads for a co-driver', (await pads()) === 2 ? [] : [`expected 2 visible pads, saw ${await pads()}`]);
  await page.selectOption('#driver1_choice', { index: 0 });
```

(The seeded new user has one profile, themself, at index 0.) After submitting, on the `submitted sheet` page, add:

```js
  const sigImgs = await page.locator('.sheet-doc img[src*="signature"]').count();
  report('submitted sheet has both signatures', sigImgs >= 2 ? [] : [`expected 2 signature images, found ${sigImgs}`]);
```

Before relying on `img[src*="signature"]`, check `techSheetSignatureResolverWeb()` in `tech-sheet-files.php` for the URL pattern and adjust the selector to match it.

- [ ] **Step 7: Run everything**

Run: `node --test "tests/js/*.test.js"`, `php phpunit.phar`, and the audit — expected all green / `All pages pass`.

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/js/driver-choice.js wcma-calculator/tests/js/driver-choice.test.js wcma-calculator/ice-sheet-page.php wcma-calculator/tech-sheets.php wcma-calculator/js/tech-sheet-form.js wcma-calculator/css/hub.css wcma-calculator/tests/IceSheetPageTest.php wcma-calculator/tests/TechSheetViewSourceTest.php wcma-calculator/tests/ux/audit.mjs
git commit -m "feat(ux): one signature when you drive your own car; signature errors name the box"
```

---

### Task 4: Keep answers through interruptions (§C1)

**Files:**
- Create: `wcma-calculator/js/tech-sheet-draft.js`, `wcma-calculator/tests/js/tech-sheet-draft.test.js`
- Modify: `wcma-calculator/js/tech-sheet-form.js` (expose state; seed the ice re-render)
- Modify: `wcma-calculator/ice-sheet-page.php`, `wcma-calculator/tech-sheets.php` (draft key, script, clear on view)
- Modify: `wcma-calculator/tests/IceSheetPageTest.php`, `wcma-calculator/tests/TechSheetViewSourceTest.php`, `wcma-calculator/tests/ux/audit.mjs`

**Interfaces:**
- Produces:
  - `techSheetDraftKey(int $userId, int $carId, int $eventId): string` (PHP, in `ice-sheet-lib.php`) → `"wcma-tsdraft:$userId:$carId:$eventId"`.
  - `window.TECH_SHEET_DRAFT_KEY` set only on new sheets.
  - `WcmaTechSheetDraft` (`module.exports` in node): `MAX_AGE_MS`, `encode(data, now): string`, `decode(raw, now): object|null`, `hasStorage(store): object|null`.
  - `window.WcmaTechSheetForm.state(): {checklist: object, equipment: object}` from `tech-sheet-form.js`.

- [ ] **Step 1: Failing node test** — `tests/js/tech-sheet-draft.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { MAX_AGE_MS, encode, decode, hasStorage } = require('../../js/tech-sheet-draft.js');

const now = Date.UTC(2026, 8, 28);
const data = { fields: { car_weight: '2700' }, checklist: { brakes: { status: 'ok' } }, equipment: {}, logBook: '1' };

test('a draft round-trips', () => {
    assert.deepStrictEqual(decode(encode(data, now), now + 1000), data);
});

test('drafts older than 14 days, from the future, or unreadable are ignored', () => {
    assert.strictEqual(MAX_AGE_MS, 14 * 24 * 3600 * 1000);
    assert.strictEqual(decode(encode(data, now), now + MAX_AGE_MS + 1), null);
    assert.deepStrictEqual(decode(encode(data, now), now + MAX_AGE_MS), data);
    assert.strictEqual(decode(encode(data, now + 3600 * 1000), now), null);
    assert.strictEqual(decode('not json', now), null);
    assert.strictEqual(decode(null, now), null);
    assert.strictEqual(decode(JSON.stringify({ savedAt: now }), now), null);
});

test('hasStorage returns the store only when it can write', () => {
    const ok = { setItem() {}, removeItem() {} };
    assert.strictEqual(hasStorage(ok), ok);
    assert.strictEqual(hasStorage({ setItem() { throw new Error('private mode'); }, removeItem() {} }), null);
    assert.strictEqual(hasStorage(undefined), null);
});
```

Run: `node --test tests/js/tech-sheet-draft.test.js` — expected FAIL.

- [ ] **Step 2: Create `js/tech-sheet-draft.js`**

```js
// wcma-calculator/js/tech-sheet-draft.js
// Keeps a new tech sheet's answers in this browser while it is filled in, so a phone call, a reload
// or a flat battery doesn't lose them (mobile UX spec 2026-09-28 §C1). Signatures are never saved.
// Load it before tech-sheet-form.js: it restores values and the checklist/equipment globals first.
(function (root) {
    'use strict';
    const MAX_AGE_MS = 14 * 24 * 3600 * 1000;
    const FIELDS = ['event_id', 'sheet_type', 'car_colour', 'car_weight', 'ice_class', 'entrant_name', 'driver1_choice', 'driver1_new_name', 'engine_hp'];

    function encode(data, now) { return JSON.stringify({ savedAt: now, data: data }); }

    function decode(raw, now) {
        if (!raw) return null;
        let parsed;
        try { parsed = JSON.parse(raw); } catch (e) { return null; }
        if (!parsed || typeof parsed.savedAt !== 'number' || !parsed.data || typeof parsed.data !== 'object') return null;
        if (now - parsed.savedAt > MAX_AGE_MS || parsed.savedAt - now > 60 * 1000) return null;
        return parsed.data;
    }

    function hasStorage(store) {
        try { store.setItem('wcma-storage-check', '1'); store.removeItem('wcma-storage-check'); return store; } catch (e) { return null; }
    }

    const api = { MAX_AGE_MS: MAX_AGE_MS, FIELDS: FIELDS, encode: encode, decode: decode, hasStorage: hasStorage };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
    root.WcmaTechSheetDraft = api;

    const key = root.TECH_SHEET_DRAFT_KEY;
    let store = null;
    try { store = key ? hasStorage(root.localStorage) : null; } catch (e) { store = null; }
    if (!store) return;
    const doc = root.document;
    const form = doc.getElementById('tech-sheet-form');
    if (!form) return;

    let saved = null;
    try { saved = decode(store.getItem(key), Date.now()); if (saved === null) store.removeItem(key); } catch (e) { saved = null; }

    if (saved) {
        const fields = saved.fields || {};
        FIELDS.forEach(function (id) {
            const el = doc.getElementById(id);
            if (el && fields[id] != null && fields[id] !== '') el.value = fields[id];
        });
        if (saved.checklist) root.TECH_SHEET_EXISTING_CHECKLIST = saved.checklist;
        if (saved.equipment) root.TECH_SHEET_EXISTING_EQUIPMENT = saved.equipment;
        if (saved.logBook != null) {
            const radio = form.querySelector('input[name="log_book_turned_in"][value="' + saved.logBook + '"]');
            if (radio) radio.checked = true;
        }
        const notice = doc.createElement('div');
        notice.className = 'form-messages show info draft-notice';
        notice.setAttribute('role', 'status');
        notice.textContent = 'We kept your answers from earlier. ';
        const again = doc.createElement('button');
        again.type = 'button';
        again.className = 'btn btn-secondary';
        again.id = 'draft-start-over';
        again.textContent = 'Start over';
        again.addEventListener('click', function () {
            try { store.removeItem(key); } catch (e) { /* nothing to clear */ }
            form.reset();
            root.location.assign(root.location.pathname + root.location.search);
        });
        notice.appendChild(again);
        form.insertBefore(notice, form.firstChild);
    }

    function collect() {
        const fields = {};
        FIELDS.forEach(function (id) { const el = doc.getElementById(id); if (el) fields[id] = el.value; });
        const s = root.WcmaTechSheetForm ? root.WcmaTechSheetForm.state() : {};
        const log = form.querySelector('input[name="log_book_turned_in"]:checked');
        return { fields: fields, checklist: s.checklist || {}, equipment: s.equipment || {}, logBook: log ? log.value : null };
    }

    let pending = null;
    function scheduleSave() {
        clearTimeout(pending);
        pending = setTimeout(function () {
            try { store.setItem(key, encode(collect(), Date.now())); } catch (e) { /* full or blocked: carry on without a draft */ }
        }, 150);
    }
    ['input', 'change', 'click'].forEach(function (type) { form.addEventListener(type, scheduleSave); });
})(typeof window !== 'undefined' ? window : globalThis);
```

Run the node test — expected PASS.

- [ ] **Step 3: Expose form state and seed the ice re-render (`js/tech-sheet-form.js`)**

At the end of the IIFE, before `})();`:

```js
    window.WcmaTechSheetForm = { state: function () { return { checklist: checklistWidget.getState(), equipment: driver1State }; } };
```

Change `function onIceClassChange()` to `function onIceClassChange(seed)` and its `carried` line to

```js
            const carried = WcmaIceClass.carryChecklistState(Object.assign({}, seed || {}, checklistWidget.getState()), sections);
```

change the listener to `iceClassSelect.addEventListener('change', function () { onIceClassChange(); });` and the on-load sync to `if (iceClassSelect.value !== (window.ICE_RENDERED_CLASS || '')) onIceClassChange(window.TECH_SHEET_EXISTING_CHECKLIST);` — so a restored draft's answers survive the first render (the page first renders the empty class, which drops unknown items).

- [ ] **Step 4: Failing PHP tests for the key and wiring**

Add to `tests/IceSheetPageTest.php`:

```php
    public function testNewSheetsCarryADraftKeyEditsDoNot(): void
    {
        $this->assertSame('wcma-tsdraft:7:3:12', techSheetDraftKey(7, 3, 12));
        $new = $this->renderNewSheet();
        $this->assertStringContainsString('window.TECH_SHEET_DRAFT_KEY = "wcma-tsdraft:', $new);
        $this->assertLessThan(strpos($new, 'js/tech-sheet-form.js'), strpos($new, 'js/tech-sheet-draft.js'));
    }
```

and, if the file has an edit-sheet render helper, assert the edit HTML does **not** contain `TECH_SHEET_DRAFT_KEY`. Add to `TechSheetViewSourceTest.php`:

```php
    public function testViewingASheetClearsItsDraftAndTheSummerFormKeepsOne(): void
    {
        $this->assertStringContainsString('localStorage.removeItem(<?= json_encode(techSheetDraftKey(', $this->viewBody());
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../tech-sheets.php'));
        $this->assertStringContainsString('<script src="js/tech-sheet-draft.js"></script>', $src);
    }
```

Run them — expected FAIL.

- [ ] **Step 5: Implement the PHP side**

`ice-sheet-lib.php`:

```php
/** The browser key a new tech sheet's draft is kept under (mobile UX spec §C1): one per user, car and event. */
function techSheetDraftKey(int $userId, int $carId, int $eventId): string {
    return 'wcma-tsdraft:' . $userId . ':' . $carId . ':' . $eventId;
}
```

`ice-sheet-page.php`: `iceSheetFormVm()` gains `'draftKey' => $sheet === null ? techSheetDraftKey((int)$car['owner_user_id'], (int)$car['id'], (int)$event['id']) : null`. In the `<script>` block add
`. ($vm['draftKey'] !== null ? 'window.TECH_SHEET_DRAFT_KEY = ' . json_encode($vm['draftKey']) . ';' : '')`,
and add `<script src="js/tech-sheet-draft.js"></script>` right before `<script src="js/tech-sheet-form.js"></script>`.

`tech-sheets.php` summer form: in its inline `<script>`, `<?php if (!$isEdit): ?>window.TECH_SHEET_DRAFT_KEY = <?= json_encode(techSheetDraftKey((int)$user['id'], (int)$car['id'], (int)$selectedEventId)) ?>;<?php endif; ?>` (use the variable names that template already has for the user and the selected event), and `<script src="js/tech-sheet-draft.js"></script>` before `js/tech-sheet-form.js`.

`handleView()`: just before `<script src="js/form-feedback.js"></script>` add

```php
<script>try { localStorage.removeItem(<?= json_encode(techSheetDraftKey((int)$sheet['user_id'], (int)$sheet['car_id'], (int)$sheet['event_id'])) ?>); } catch (e) {}</script>
```

Run the PHP tests — expected PASS.

- [ ] **Step 6: Audit check** — in `audit.mjs`, on the ice sheet after choosing the class (and after the Task 2/3 checks), add before filling the rest:

```js
  await page.fill('input[name=car_weight]', '2700');
  const firstSection = page.locator('.checklist-section-header').first();
  await firstSection.click();
  await page.locator('button:text-is("OK")').first().click();
  await page.waitForTimeout(400);
  await page.reload();
  await page.waitForLoadState('networkidle');
  const kept = await page.evaluate(() => ({
    notice: !!document.querySelector('.draft-notice'),
    weight: document.getElementById('car_weight').value,
    cls: document.getElementById('ice_class').value,
    okCount: document.querySelectorAll('.checklist-chip-selected-ok').length,
  }));
  report('answers kept after a reload', kept.notice && kept.weight === '2700' && kept.cls === 'SS' && kept.okCount >= 1
    ? [] : [`expected notice, weight 2700, class SS and a ticked item, got ${JSON.stringify(kept)}`]);
  await audit(page, 'ice tech sheet with a kept draft');
```

(`okCount` counts ticked chips in open sections; open the first section again first if the checklist renders collapsed: click `.checklist-section-header` before counting.) At the end of the flow, after the car page, add: `await page.goto(BASE + '/tech-sheets.php?action=new-ice&car_id=' + carId + '&event_id=' + eventId)` — use the ids from the sheet URL you already have — and report a problem if `.draft-notice` exists (the submit cleared it).

- [ ] **Step 7: Run everything** — node tests, PHPUnit, audit: all green.

- [ ] **Step 8: Commit**

```bash
git add wcma-calculator/js/tech-sheet-draft.js wcma-calculator/tests/js/tech-sheet-draft.test.js wcma-calculator/js/tech-sheet-form.js wcma-calculator/ice-sheet-lib.php wcma-calculator/ice-sheet-page.php wcma-calculator/tech-sheets.php wcma-calculator/tests/IceSheetPageTest.php wcma-calculator/tests/TechSheetViewSourceTest.php wcma-calculator/tests/ux/audit.mjs
git commit -m "feat(ux): tech sheet keeps your answers on this phone until you submit"
```

---

### Task 5: The submitted sheet reads naturally (§C5)

**Files:**
- Modify: `wcma-calculator/tech-status.php` (new `iceSeasonLabel()`; `techCarStatusLabel()`)
- Modify: the other "Ice $season" sites: `tech-sheet-render.php:69`, `gear-lib.php:41`, `gear-email.php:14,75`, `gear-page.php:31,53`, `inspect-lib.php:139`, `pretech-email.php:94`, `pretech-page.php:111`, `readiness-lib.php:129`
- Modify: `tech-sheet-render.php:77` (engine line)
- Modify: `css/hub.css` (gear rows stack)
- Test: `tests/TechStatusTest.php`, `tests/TechSheetRenderTest.php`, plus the existing tests that assert "Ice 2026/2027/2028" (15 files)

**Interfaces:**
- Produces: `iceSeasonLabel(int $season): string` → `'Winter ' . ($season - 1) . '–' . substr((string)$season, -2)`, e.g. 2027 → "Winter 2026–27". `techSheetEngineLine(?string $cc, ?string $hp): string`.

- [ ] **Step 1: Failing tests**

Add to `tests/TechStatusTest.php`:

```php
    public function testIceSeasonReadsAsAWinter(): void
    {
        $this->assertSame('Winter 2026–27', iceSeasonLabel(2027));
        $this->assertSame('Winter 2099–00', iceSeasonLabel(2100));
        $this->assertStringContainsString('Winter 2026–27', techCarStatusLabel(['state' => 'accepted', 'via' => 'photos'], 2027, DISCIPLINE_ICE));
        $this->assertStringNotContainsString('Ice 2027', techCarStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2027, DISCIPLINE_ICE));
        $this->assertSame('2026', substr(techCarStatusLabel(['state' => 'accepted', 'via' => 'in_person'], 2026), -4));
    }
```

Add to `tests/TechSheetRenderTest.php` (use its existing sheet fixture helper):

```php
    public function testEngineLineShowsOnlyWhatWasEntered(): void
    {
        $this->assertSame('140 HP', techSheetEngineLine(null, '140'));
        $this->assertSame('1998 cc', techSheetEngineLine('1998', ''));
        $this->assertSame('1998 cc / 140 HP', techSheetEngineLine('1998', '140'));
        $this->assertSame('—', techSheetEngineLine('', null));
    }
```

and an assertion that a rendered ice sheet (season 2027) contains `Winter 2026–27` and not `Ice 2027`.

Run: `php phpunit.phar --filter "TechStatusTest|TechSheetRenderTest"` — expected FAIL.

- [ ] **Step 2: Implement**

`tech-status.php`, next to the `DISCIPLINE_*` constants:

```php
/** How an ice season reads to people: stored season 2027 is the winter of 2026–27 (mobile UX spec §C5). */
function iceSeasonLabel(int $season): string {
    return 'Winter ' . ($season - 1) . '–' . substr((string)$season, -2);
}
```

In `techCarStatusLabel()`: `$when = $discipline === DISCIPLINE_ICE ? iceSeasonLabel($season) : (string)$season;`

`tech-sheet-render.php`:

```php
/** "140 HP", "1998 cc" or "1998 cc / 140 HP" — only the parts entered (mobile UX spec §C5). */
function techSheetEngineLine(?string $cc, ?string $hp): string {
    $parts = [];
    if (trim((string)$cc) !== '') $parts[] = trim((string)$cc) . ' cc';
    if (trim((string)$hp) !== '') $parts[] = trim((string)$hp) . ' HP';
    return $parts ? implode(' / ', $parts) : '—';
}
```

and line 77's engine cell becomes `'<strong>Engine:</strong> ' . h(techSheetEngineLine($sheet['engine_cc'] ?? null, $sheet['engine_hp'] ?? null))`. Line 69's `' · Ice ' . (int)($sheet['season'] ?? 0)` becomes `' · ' . iceSeasonLabel((int)($sheet['season'] ?? 0))`.

Replace every other `'Ice ' . $season`-style display (list above) with `iceSeasonLabel(...)`. Each of those files must have `tech-status.php` loaded: check with `grep -n "tech-status" <file>` and its callers; where missing, add `require_once __DIR__ . '/tech-status.php';` — and run `php phpunit.phar --filter RequireOnceGuardTest` to make sure no file plain-`require`s it elsewhere.

- [ ] **Step 3: Update the existing expectations**

The 33 existing assertions that expect "Ice 2026/2027/2028" now expect the winter label. From `wcma-calculator/`:

```bash
grep -rl "Ice 202[678]" tests/*.php | xargs sed -i -e 's/Ice 2026/Winter 2025–26/g' -e 's/Ice 2027/Winter 2026–27/g' -e 's/Ice 2028/Winter 2027–28/g'
```

Then read the diff of those test files: each changed line must be a display string, not a stored value or a URL. Revert any that aren't.

- [ ] **Step 4: Gear rows stack on narrow screens** — Phase C CSS section:

```css
/* Driver gear rows: the item name above its rating input on narrow screens (spec §C5). */
@media (max-width: 480px) {
  body.hub #equipment-container .checklist-item-row { flex-wrap: wrap; }
  body.hub #equipment-container .checklist-item-row input[type="text"] { flex: 1 1 100%; margin: 6px 0 0 !important; }
}
```

- [ ] **Step 5: Run everything** — PHPUnit, node tests, audit — all green. Screenshot the ice sheet's gear rows at 375px (throwaway server on 8160, `scratch/ux/step.js` + `clips.js`) and check "Suit or FR coveralls" sits on one line above its input.

- [ ] **Step 6: Commit**

```bash
git add -A wcma-calculator
git commit -m "feat(ux): ice seasons read as 'Winter 2026–27', engine line shows only what was entered, gear rows stack"
```

---

### Task 6: Walk the finished form

- [ ] **Step 1:** Run `bash wcma-calculator/tests/ux/run-audit.sh` — `All pages pass`.
- [ ] **Step 2:** On the throwaway server (port 8160, fresh seed as in the Phase A plan's Task 7), walk an ice sheet at 375px by hand with `scratch/ux/step.js`: submit empty (message + focus on weight), fill half, reload (answers kept), Start over (cleared), pick a co-driver (two pads), back to you (one pad, labelled "Your signature (entrant and driver)"), submit, view (Winter 2026–27, engine "140 HP", two signatures). Record the result in the ledger. Fix anything found with a test first.

---

## Self-review notes

- §C1 → Task 4; §C2 → Task 1; §C3 → Task 2; §C4 → Task 3; §C5 → Task 5.
- Additional endurance drivers (summer) are not kept in drafts: they are rare, built dynamically, and the spec lists the fields to keep (weight, class, HP, engine fields, checklist, gear, log book). Driver 1 and its gear are kept.
- The one-signature rule uses Driver 1 only, so endurance sheets behave the same as standard ones for the entrant/driver pads.
