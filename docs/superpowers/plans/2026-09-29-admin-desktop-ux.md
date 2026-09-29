# Admin Desktop UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The admin tabs read well in a desktop browser and stay phone-safe: read-only lists with one Edit button per row that opens a modal, shared form grid, chips as spans, a proper page shell.

**Architecture:** Shared pure helpers in a new `admin-ui.php` (page shell, chips, modal, flash placement). Each tab gets its own file with its handlers and a pure `render…PageHtml()` that PHPUnit can test (the clubs tab already works this way); `admin.php` becomes a router. Modals are native `<dialog>` elements rendered after each table and opened by a small `js/admin-dialog.js`.

**Tech Stack:** PHP 8.3, SQLite, vanilla JS, CSS; PHPUnit (`php phpunit.phar` from `wcma-calculator/`), node tests (`node --test tests/js/*.test.js` from `wcma-calculator/`, unquoted glob), phone audit (`bash wcma-calculator/tests/ux/run-audit.sh` from the repo root; needs `npm install` in `wcma-calculator/tests/ux` once).

**Spec:** `docs/superpowers/specs/2026-09-29-admin-desktop-ux-design.md`

## Global Constraints

- Tap targets stay ≥ 44px, fields 48px, no text under 16px (Phase B rules); the phone audit must pass.
- One solid primary button per form; row action is one secondary **Edit**; Deactivate/Reactivate/Remove never share a form with Save and keep their `data-confirm`.
- Every POST keeps `adminRequirePost()` (POST + CSRF) in `admin.php`.
- Error from a modal → redirect with `&edit=<id|code|new>`; the message shows inside that modal, which opens on load. Success → list with the flash at the top.
- A click on the modal backdrop does not close it; Cancel and Esc do.
- Copy is sentence case, plain words ("Add event", "Edit", "Save", "Cancel").
- Branch `admin-desktop-ux`; commit messages end with the project's two attribution lines.
- Baseline: PHPUnit 962 tests; node 52; phone audit all pass.

## Review Focus

1. **Deactivate inside a modal:** the page behind a modal `<dialog>` is inert, so the confirm box must render inside the dialog or it can't be clicked. Pinned by the audit step in Task 7 (clicks Deactivate, then Cancel on the confirm, and checks the modal is still open).
2. **`?edit=` pointing at a row that doesn't exist** (hand-edited URL, deleted link): the error must still show, at the top. Pinned in Task 1 (`adminFlashPlacement` with unknown key).
3. **Saving a user who is the last admin**, or giving a single-name account the inspector role: nothing is saved (not even the name). Pinned in Task 3 (`adminUserSaveError` runs before any write; source test checks order).
4. **Table search matching text inside hidden modals** (e.g. searching "Deactivate" matches every row): dialogs must sit outside `<table>`. Pinned in Tasks 3–5 (`</table>` before first `<dialog`).
5. **Editing an event whose club is now inactive** must not silently change its club. Pinned in Task 4 (inactive current club stays selected).

---

### Task 0: Branch and baseline

**Files:** none changed except the spec commit.

- [ ] **Step 1:** `git checkout -b admin-desktop-ux` (from `main`, clean tree apart from the spec and this plan).
- [ ] **Step 2:** From `wcma-calculator/`: `php phpunit.phar` → expect `OK (962 tests, …)`; `node --test tests/js/*.test.js` → note pass count; from the repo root `bash wcma-calculator/tests/ux/run-audit.sh` → expect `All pages pass`. Write the three numbers into this plan's Global Constraints line.
- [ ] **Step 3:** Commit spec and plan:

```bash
git add docs/superpowers/specs/2026-09-29-admin-desktop-ux-design.md docs/superpowers/plans/2026-09-29-admin-desktop-ux.md
git commit -m "docs: admin desktop UX spec and plan"
```

---

### Task 1: Shared admin UI helpers

**Files:**
- Create: `wcma-calculator/admin-ui.php`
- Test: `wcma-calculator/tests/AdminUiTest.php`

**Interfaces (produces — later tasks use exactly these):**
- `adminRedirect(string $url): never`
- `adminCsrfField(string $csrf): string`
- `adminChip(string $text, string $kind): string` — `$kind` ∈ `ok|fail|pending|info|ink`
- `adminEditTarget(array $get): ?string`
- `adminFlashPlacement(?array $flash, ?string $edit, array $dialogKeys): array{top: ?array, dialog: ?array}`
- `adminFlashHtml(?array $flash): string`
- `adminEditButton(string $dialogId, string $name): string`
- `adminAddButton(string $dialogId, string $label): string`
- `adminDialogActions(string $submitLabel): string`
- `adminDangerHtml(string $heading, string $text, string $formHtml): string`
- `adminDialogHtml(string $id, string $title, string $bodyHtml, ?array $flash = null, string $subtitle = ''): string`
- `adminField(string $id, string $label, string $inputHtml, bool $wide = false): string`
- `adminRenderPage(string $title, string $tab, string $bodyHtml, ?array $topFlash, string $extraScripts = ''): void`

- [ ] **Step 1: Write the failing test** — `tests/AdminUiTest.php`:

```php
<?php
// wcma-calculator/tests/AdminUiTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';

use PHPUnit\Framework\TestCase;

final class AdminUiTest extends TestCase
{
    public function testEditTargetAcceptsIdsCodesAndNewOnly(): void
    {
        $this->assertSame('12', adminEditTarget(['edit' => '12']));
        $this->assertSame('NASCC', adminEditTarget(['edit' => 'NASCC']));
        $this->assertSame('new', adminEditTarget(['edit' => 'new']));
        $this->assertNull(adminEditTarget(['edit' => '<x>']));
        $this->assertNull(adminEditTarget(['edit' => '']));
        $this->assertNull(adminEditTarget(['edit' => ['a']]));
        $this->assertNull(adminEditTarget([]));
    }

    public function testAnErrorFromAModalGoesInsideIt(): void
    {
        $err = ['type' => 'error', 'message' => 'Enter a name.'];
        $ok = ['type' => 'success', 'message' => 'Saved.'];
        $this->assertSame(['top' => null, 'dialog' => $err], adminFlashPlacement($err, '3', ['3', '4']));
        $this->assertSame(['top' => $ok, 'dialog' => null], adminFlashPlacement($ok, '3', ['3']));
        $this->assertSame(['top' => $err, 'dialog' => null], adminFlashPlacement($err, null, ['3']));
        $this->assertSame(['top' => null, 'dialog' => null], adminFlashPlacement(null, '3', ['3']));
    }

    public function testAnErrorForAModalThatIsNotOnThePageStillShowsAtTheTop(): void
    {
        $err = ['type' => 'error', 'message' => 'Enter a name.'];
        $this->assertSame(['top' => $err, 'dialog' => null], adminFlashPlacement($err, '99', ['3', '4']));
    }

    public function testDialogOpensOnLoadOnlyWhenItCarriesAMessage(): void
    {
        $plain = adminDialogHtml('user-dialog-3', 'Edit <Jo>', '<form></form>', null, 'jo@example.com');
        $this->assertStringContainsString('<dialog class="admin-dialog" id="user-dialog-3" aria-labelledby="user-dialog-3-title">', $plain);
        $this->assertStringContainsString('<h2 id="user-dialog-3-title">Edit &lt;Jo&gt;</h2>', $plain);
        $this->assertStringContainsString('jo@example.com', $plain);
        $this->assertStringNotContainsString('data-open-on-load', $plain);

        $withError = adminDialogHtml('user-dialog-3', 'Edit Jo', '<form></form>', ['type' => 'error', 'message' => 'Add a <last> name.']);
        $this->assertStringContainsString('data-open-on-load', $withError);
        $this->assertStringContainsString('role="alert">Add a &lt;last&gt; name.</div>', $withError);
        $this->assertLessThan(strpos($withError, '<form>'), strpos($withError, 'role="alert"'));
    }

    public function testEditAndAddButtonsPointAtTheirDialog(): void
    {
        $edit = adminEditButton('user-dialog-3', 'Jordan Lee');
        $this->assertStringContainsString('type="button"', $edit);
        $this->assertStringContainsString('data-dialog-open="user-dialog-3"', $edit);
        $this->assertStringContainsString('aria-label="Edit Jordan Lee"', $edit);
        $this->assertStringContainsString('btn btn-secondary', $edit);
        $add = adminAddButton('event-dialog-new', 'Add event');
        $this->assertStringContainsString('data-dialog-open="event-dialog-new"', $add);
        $this->assertStringContainsString('btn btn-primary', $add);
    }

    public function testDialogActionsAreCancelThenSave(): void
    {
        $html = adminDialogActions('Save');
        $cancel = strpos($html, 'data-dialog-close>Cancel</button>');
        $save = strpos($html, '<button type="submit" class="btn btn-primary">Save</button>');
        $this->assertNotFalse($cancel);
        $this->assertNotFalse($save);
        $this->assertLessThan($save, $cancel);
        $this->assertStringContainsString('<button type="button" class="btn btn-secondary" data-dialog-close>', $html);
    }

    public function testChipIsASpanAndFieldLabelsItsControl(): void
    {
        $this->assertSame('<span class="admin-chip admin-chip--ok">Active</span>', adminChip('Active', 'ok'));
        $field = adminField('x-name', 'Name', '<input id="x-name">', true);
        $this->assertSame('<div class="admin-field admin-form-wide"><label for="x-name">Name</label><input id="x-name"></div>', $field);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php phpunit.phar tests/AdminUiTest.php` → FAIL (`admin-ui.php` not found).

- [ ] **Step 3: Implement** — `admin-ui.php`:

```php
<?php
// wcma-calculator/admin-ui.php
//
// Shared pieces of the admin tabs (admin desktop UX spec 2026-09-29): page shell, chips, the edit
// modal and where a flash message goes. Pure except adminRedirect() and adminRenderPage().

/** POST/redirect/GET: send the browser to $url and stop. */
function adminRedirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function adminCsrfField(string $csrf): string {
    return '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">';
}

/** A status chip. $kind: ok, fail, pending, info or ink. */
function adminChip(string $text, string $kind): string {
    return '<span class="admin-chip admin-chip--' . h($kind) . '">' . h($text) . '</span>';
}

/** Which modal to reopen: ?edit=<id|code|new> (1–20 letters, digits or dashes), else null. */
function adminEditTarget(array $get): ?string {
    $v = $get['edit'] ?? null;
    return is_string($v) && preg_match('/^[A-Za-z0-9-]{1,20}$/', $v) ? $v : null;
}

/**
 * Where the flash goes: an error sent back from a modal that is on this page ($edit is one of
 * $dialogKeys) shows inside it; everything else at the top of the page.
 * @return array{top: ?array, dialog: ?array}
 */
function adminFlashPlacement(?array $flash, ?string $edit, array $dialogKeys): array {
    if ($flash !== null && $edit !== null && ($flash['type'] ?? '') === 'error'
        && in_array($edit, array_map('strval', $dialogKeys), true)) {
        return ['top' => null, 'dialog' => $flash];
    }
    return ['top' => $flash, 'dialog' => null];
}

function adminFlashHtml(?array $flash): string {
    if ($flash === null) return '';
    return '<div class="form-messages show ' . h((string)$flash['type']) . '" role="alert">' . h((string)$flash['message']) . '</div>';
}

/** The row's Edit button; $name tells screen readers what it edits. */
function adminEditButton(string $dialogId, string $name): string {
    return '<button type="button" class="btn btn-secondary admin-edit" data-dialog-open="' . h($dialogId)
        . '" aria-label="Edit ' . h($name) . '">Edit</button>';
}

/** The primary "Add …" button above a list; opens the add modal. */
function adminAddButton(string $dialogId, string $label): string {
    return '<button type="button" class="btn btn-primary admin-add" data-dialog-open="' . h($dialogId) . '">' . h($label) . '</button>';
}

/** Cancel + primary submit at the foot of a modal form. */
function adminDialogActions(string $submitLabel): string {
    return '<div class="admin-dialog-actions admin-form-wide">'
        . '<button type="button" class="btn btn-secondary" data-dialog-close>Cancel</button>'
        . '<button type="submit" class="btn btn-primary">' . h($submitLabel) . '</button></div>';
}

/** The separate section under a modal's form for Deactivate / Reactivate. $formHtml is the whole <form>. */
function adminDangerHtml(string $heading, string $text, string $formHtml): string {
    return '<section class="admin-dialog-danger"><h3>' . h($heading) . '</h3><p>' . h($text) . '</p>' . $formHtml . '</section>';
}

/**
 * One modal. $bodyHtml is trusted markup (its forms). A $flash (an error sent back from this modal)
 * shows above the form, and the modal opens as the page loads.
 */
function adminDialogHtml(string $id, string $title, string $bodyHtml, ?array $flash = null, string $subtitle = ''): string {
    return '<dialog class="admin-dialog" id="' . h($id) . '" aria-labelledby="' . h($id) . '-title"'
        . ($flash !== null ? ' data-open-on-load' : '') . '>'
        . '<div class="admin-dialog-body">'
        . '<h2 id="' . h($id) . '-title">' . h($title) . '</h2>'
        . ($subtitle !== '' ? '<p class="admin-dialog-sub">' . h($subtitle) . '</p>' : '')
        . adminFlashHtml($flash) . $bodyHtml . '</div></dialog>';
}

/** A labelled control in an .admin-form grid; $wide spans the whole row. */
function adminField(string $id, string $label, string $inputHtml, bool $wide = false): string {
    return '<div class="admin-field' . ($wide ? ' admin-form-wide' : '') . '"><label for="' . h($id) . '">'
        . h($label) . '</label>' . $inputHtml . '</div>';
}

/** Every admin tab's page: hub layout with the Admin tabs, the title, the body, the modal scripts. */
function adminRenderPage(string $title, string $tab, string $bodyHtml, ?array $topFlash, string $extraScripts = ''): void {
    renderPageStart($title, 'admin', ['subnav' => adminSubnavHtml($tab), 'flash' => $topFlash, 'bodyClass' => 'admin']);
    echo '<h1 class="hub-page-title">' . h($title) . '</h1>' . $bodyHtml;
    $scripts = '';
    foreach (['js/admin-dialog.js', 'js/confirm-modal.js', 'js/form-feedback.js'] as $src) {
        $scripts .= '<script src="' . hubAsset($src) . '"></script>';
    }
    renderPageEnd(['scripts' => $scripts . $extraScripts]);
}
```

- [ ] **Step 4: Run to verify it passes** — `php phpunit.phar tests/AdminUiTest.php` → PASS (7 tests).
- [ ] **Step 5: Commit** — `git add wcma-calculator/admin-ui.php wcma-calculator/tests/AdminUiTest.php && git commit -m "feat(admin): shared admin UI helpers — modal, chips, flash placement, page shell"`

---

### Task 2: Modal script, confirm-in-modal fix, admin CSS

**Files:**
- Create: `wcma-calculator/js/admin-dialog.js`, `wcma-calculator/tests/js/admin-dialog.test.js`
- Modify: `wcma-calculator/js/confirm-modal.js` (overlay host + Esc), `wcma-calculator/css/hub.css` (B2 field list line ~382; `.btn-role` rules ~355–370 and ~472–477; new admin section at the end)

**Interfaces:**
- Consumes: markup from Task 1 (`dialog.admin-dialog`, `[data-dialog-open]`, `[data-dialog-close]`, `data-open-on-load`, `.admin-form`, `.admin-form-wide`, `.admin-field`, `.admin-chip--*`, `.admin-dialog-*`, `.admin-edit`, `.admin-add`).
- Produces: `window.WcmaAdminDialog = { openDialog(dialog, opener), onClick(doc, event), init(doc) }`; CSS classes `.admin-table`, `.admin-cell-actions`, `.admin-sub`, `.admin-chips`, `.admin-toolbar`, `.admin-toolbar-filters`, `.admin-intro`, `.admin-link`, `.admin-settings`, `.admin-form-actions`, `.admin-col-order`, `.admin-row-actions` used by Tasks 3–6.

- [ ] **Step 1: Write the failing JS test** — `tests/js/admin-dialog.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { openDialog, onClick, init } = require('../../js/admin-dialog.js');

// Minimal stand-ins for the DOM pieces admin-dialog.js touches.
function fakeEl(attrs, dialog) {
    return {
        attrs, focusCount: 0,
        focus() { this.focusCount++; },
        getAttribute(name) { return this.attrs[name]; },
        closest(sel) {
            const m = sel.match(/^\[(.+)\]$/);
            if (m && m[1] in this.attrs) return this;
            if (sel === 'dialog') return dialog || null;
            return null;
        },
    };
}
function fakeDialog(fields) {
    const listeners = {};
    return {
        open: false, fields: fields || [],
        showModal() { this.open = true; },
        close() { this.open = false; (listeners.close || []).forEach(fn => fn()); listeners.close = []; },
        querySelector() { return this.fields[0] || null; },
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    };
}
function fakeDoc(dialogs, pending) {
    return {
        getElementById: id => dialogs[id] || null,
        querySelector: () => pending || null,
        addEventListener() {},
    };
}

test('opening shows the modal and puts focus in its first field', () => {
    const field = fakeEl({});
    const dialog = fakeDialog([field]);
    openDialog(dialog, null);
    assert.strictEqual(dialog.open, true);
    assert.strictEqual(field.focusCount, 1);
});

test('closing returns focus to the Edit button that opened it', () => {
    const dialog = fakeDialog([fakeEl({})]);
    const opener = fakeEl({ 'data-dialog-open': 'd' });
    openDialog(dialog, opener);
    dialog.close();
    assert.strictEqual(opener.focusCount, 1);
});

test('an Edit button opens the dialog it names', () => {
    const dialog = fakeDialog([]);
    onClick(fakeDoc({ 'user-dialog-3': dialog }), { target: fakeEl({ 'data-dialog-open': 'user-dialog-3' }) });
    assert.strictEqual(dialog.open, true);
});

test('Cancel closes the dialog it sits in', () => {
    const dialog = fakeDialog([]);
    dialog.showModal();
    onClick(fakeDoc({}), { target: fakeEl({ 'data-dialog-close': '' }, dialog) });
    assert.strictEqual(dialog.open, false);
});

test('a click anywhere else, including the backdrop, leaves the dialog open', () => {
    const dialog = fakeDialog([]);
    dialog.showModal();
    onClick(fakeDoc({}), { target: fakeEl({}, dialog) });
    assert.strictEqual(dialog.open, true);
});

test('a dialog sent back with an error opens as the page loads', () => {
    const dialog = fakeDialog([]);
    init(fakeDoc({}, dialog));
    assert.strictEqual(dialog.open, true);
});
```

- [ ] **Step 2: Run to verify it fails** — `node --test tests/js/admin-dialog.test.js` → FAIL (`Cannot find module '../../js/admin-dialog.js'`).

- [ ] **Step 3: Implement** — `js/admin-dialog.js`:

```js
// wcma-calculator/js/admin-dialog.js
// Admin edit modals (admin desktop UX spec 2026-09-29 §2). [data-dialog-open="<id>"] opens that
// <dialog class="admin-dialog">; [data-dialog-close] closes the dialog it sits in; a dialog marked
// data-open-on-load (an error sent back from it) opens as the page loads. Esc closes natively; a
// backdrop click does not, so a stray click never throws away edits. Loadable as a classic script
// (window.WcmaAdminDialog) or via require() for tests.
(function (root) {
    'use strict';

    function openDialog(dialog, opener) {
        if (dialog.open) return;
        dialog.showModal();
        const field = dialog.querySelector('input:not([type="hidden"]), select, textarea');
        if (field) field.focus();
        if (opener) dialog.addEventListener('close', function () { opener.focus(); }, { once: true });
    }

    function onClick(doc, event) {
        const target = event.target;
        if (!target || typeof target.closest !== 'function') return;
        const opener = target.closest('[data-dialog-open]');
        if (opener) {
            const dialog = doc.getElementById(opener.getAttribute('data-dialog-open'));
            if (dialog) openDialog(dialog, opener);
            return;
        }
        if (target.closest('[data-dialog-close]')) {
            const dialog = target.closest('dialog');
            if (dialog) dialog.close();
        }
    }

    function init(doc) {
        doc.addEventListener('click', function (event) { onClick(doc, event); });
        const pending = doc.querySelector('dialog.admin-dialog[data-open-on-load]');
        if (pending) openDialog(pending, null);
    }

    const api = { openDialog: openDialog, onClick: onClick, init: init };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaAdminDialog = api;
        init(root.document);
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: Run** — `node --test tests/js/*.test.js` → all pass (baseline + 6).

- [ ] **Step 5: Confirm box inside an open dialog** — in `js/confirm-modal.js`:
  - In `buildModal()`, delete the line `document.body.appendChild(overlay);`.
  - Change `function askConfirm(message) {` to `function askConfirm(message, host) {` and, right after `if (!modalEl) modalEl = buildModal();`, add:

```js
        // A modal <dialog> makes the rest of the page inert, so the box must sit inside it.
        host.appendChild(modalEl);
```

  - Change `function onKeydown(e) { if (e.key === 'Escape') cleanup(false); }` to:

```js
            function onKeydown(e) {
                if (e.key !== 'Escape') return;
                e.preventDefault();   // Esc answers the question; it must not also close an open dialog
                cleanup(false);
            }
```

  - In the submit listener change `askConfirm(message).then(` to `askConfirm(message, form.closest('dialog') || document.body).then(`.
  - Update the header comment's first line to: `Replaces native confirm() for forms marked with data-confirm="message", including forms inside an open <dialog>.`

- [ ] **Step 6: CSS** — in `css/hub.css`:
  - B2 field rule (line ~382): add `body.hub input[type="url"],` to the selector list (after `input[type="tel"]`).
  - B1 rules (lines ~357 and ~370): change `:not(.hub-btn, .link-button, .checklist-chip, .btn, .btn-role)` to `:not(.hub-btn, .link-button, .checklist-chip, .btn)` in both places.
  - Delete the block starting `/* Admin user actions (spec 2026-09-29 §2.7)` (the four `.btn-role` rules).
  - Append at the end of the file:

```css
/* ── Admin tabs (admin desktop UX spec 2026-09-29) ── */
body.admin .hub-page-title { margin: 8px 0 20px; }
body.admin .admin-intro { font-size: 16px; color: var(--hub-ink-2); max-width: 72ch; margin: -8px 0 20px; }
body.admin .detail-card h2, body.admin .detail-card h3 { font-size: 22px; color: var(--hub-ink); border-bottom: 0; padding-bottom: 0; margin: 0 0 12px; }

/* Toolbar: search, filter and the Add button on one line. */
.admin-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 16px; }
.admin-toolbar-filters { display: flex; flex-wrap: wrap; gap: 12px; flex: 1 1 auto; }
body.hub .admin-toolbar .table-search { flex: 1 1 260px; max-width: 360px; width: auto; margin: 0; }
body.hub .admin-toolbar .table-filter { flex: 0 0 auto; width: auto; margin: 0; }

/* Form grid: fields side by side on desktop, one column on phones. */
.admin-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px 20px; max-width: 880px; align-items: start; }
.admin-form-wide { grid-column: 1 / -1; }
.admin-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.admin-field label { font-size: 16px; font-weight: 700; color: var(--hub-ink); }
body.hub .admin-field input, body.hub .admin-field select { width: 100%; box-sizing: border-box; margin: 0; }
.admin-form .form-hint { margin: 0; }
.admin-form-actions { display: flex; gap: 12px; flex-wrap: wrap; }
body.hub fieldset.radio-row { border: 0; padding: 0; margin: 0; min-width: 0; display: flex; flex-wrap: wrap; gap: 0 24px; }
body.hub fieldset.radio-row legend { padding: 0; margin-bottom: 6px; font-size: 16px; font-weight: 700; color: var(--hub-ink); }

/* Chips (status, role) sit inside a cell, never on it. */
.admin-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.admin-chip { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 16px; font-weight: 700; line-height: 1.4; white-space: nowrap; }
.admin-chip--ok { color: var(--hub-ok-ink); background: var(--hub-ok-bg); }
.admin-chip--fail { color: var(--hub-red-ink); background: var(--hub-todo-bg); }
.admin-chip--pending { color: var(--hub-warn-ink); background: var(--hub-warn-bg); }
.admin-chip--info { color: var(--hub-ink-2); background: var(--hub-paper); }
.admin-chip--ink { color: #fff; background: var(--hub-ink); }

/* Tables: read-only rows, one Edit button at the right. */
body.hub .data-table.admin-table { display: table; white-space: normal; }
body.hub .admin-table th { font-size: 16px; }
body.hub .admin-table td { vertical-align: middle; }
body.admin .data-table .empty-row { color: var(--hub-ink-2); }
.admin-table .admin-sub { display: block; font-size: 16px; color: var(--hub-ink-2); overflow-wrap: anywhere; }
.admin-table .admin-cell-actions { text-align: right; white-space: nowrap; width: 1%; }
body.hub .admin-edit { min-height: 44px; padding: 0 16px; font-size: 16px; }
body.hub .admin-link { display: inline-flex; align-items: center; min-height: 44px; color: var(--hub-red-ink); }
.admin-table .admin-col-order { width: 6rem; }
body.hub .admin-table .admin-col-order input { width: 5rem; }
.admin-row-actions { display: flex; gap: 24px; align-items: center; justify-content: flex-end; }

/* Settings: one card, three groups. */
.admin-settings { max-width: 720px; }
.admin-settings fieldset { border: 0; padding: 0; margin: 0 0 24px; min-width: 0; }
.admin-settings legend { font-family: var(--hub-font-narrow); font-size: 22px; font-weight: 700; margin-bottom: 8px; padding: 0; }

/* Edit modal. */
dialog.admin-dialog { width: min(640px, calc(100vw - 32px)); max-width: none; max-height: calc(100vh - 32px); padding: 0; border: 0;
  border-radius: var(--hub-radius); box-shadow: 0 20px 60px rgba(0, 0, 0, .35); color: var(--hub-ink); background: var(--hub-card); }
dialog.admin-dialog::backdrop { background: rgba(17, 19, 23, .55); }
.admin-dialog-body { padding: 24px; }
.admin-dialog h2 { margin: 0 0 4px; font-size: 26px; }
.admin-dialog-sub { margin: 0; font-size: 16px; color: var(--hub-ink-2); overflow-wrap: anywhere; }
.admin-dialog .form-messages { margin: 16px 0 0; }
.admin-dialog .admin-form { max-width: none; margin-top: 20px; }
.admin-dialog-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 12px; padding-top: 4px; }
.admin-dialog-danger { margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--hub-line); }
.admin-dialog-danger h3 { margin: 0 0 4px; font-size: 18px; }
.admin-dialog-danger p { margin: 0 0 12px; font-size: 16px; color: var(--hub-ink-2); }

@media (max-width: 700px) {
  /* Each row becomes a card: "Label  value" lines, Edit full width at the bottom. */
  body.hub .data-table.admin-table, .admin-table tbody, .admin-table tr, .admin-table td { display: block; width: auto; }
  .admin-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
  .admin-table tr { padding: 12px 16px; border-bottom: 1px solid var(--hub-line); }
  body.hub .admin-table td { display: flex; gap: 12px; align-items: center; padding: 4px 0; border: 0; }
  .admin-table td[data-label]::before { content: attr(data-label); flex: 0 0 7.5em; font-weight: 700; color: var(--hub-ink-2); }
  .admin-table td > input:not([type="checkbox"]) { flex: 1; min-width: 0; }
  .admin-table .admin-cell-actions { width: auto; padding-top: 8px; }
  .admin-table .admin-cell-actions .btn, .admin-row-actions { flex: 1; }
  .admin-row-actions { justify-content: flex-start; }
  body.hub .admin-table .empty-row { display: block; }
}
@media (max-width: 480px) {
  dialog.admin-dialog { width: 100vw; max-height: 100dvh; height: 100dvh; margin: 0; border-radius: 0; }
  .admin-dialog-body { padding: 16px; }
  .admin-dialog-actions .btn { flex: 1; }
}
```

- [ ] **Step 7: Run all tests** — `php phpunit.phar` → still 962 + 7 green except `AdminSourceTest::testDeactivateAndReactivateAreSecondaryButtons`, which still passes here (admin.php unchanged yet; its inline `.btn-role` style block keeps the look until Task 3). `node --test tests/js/*.test.js` → all pass.
- [ ] **Step 8: Commit** — `git add wcma-calculator/js/admin-dialog.js wcma-calculator/tests/js/admin-dialog.test.js wcma-calculator/js/confirm-modal.js wcma-calculator/css/hub.css && git commit -m "feat(admin): edit modal script, confirm box inside modals, admin layout styles"`

---

### Task 3: Users & roles — list + edit modal

**Files:**
- Create: `wcma-calculator/admin-users.php`, `wcma-calculator/tests/AdminUsersPageTest.php`
- Modify: `wcma-calculator/admin.php` (requires, router, remove users code), `wcma-calculator/tests/AdminSourceTest.php`, `wcma-calculator/tests/MediaSourceTest.php`

**Interfaces:**
- Consumes: all of Task 1.
- Produces: `adminNormalizeName(string): string`, `adminUserSaveError(array $target, string $name, string $role, int $adminCount): ?string`, `adminUserDialogHtml(array $u, string $csrf, ?array $flash): string`, `renderUsersPageHtml(array $users, array $counts, string $csrf, ?array $dialogFlash, ?string $edit): string`, `handleUsersList(PDO)`, `handleUserSave(PDO, int)`, `handleSetActive(PDO, int, bool)`. Route `user-save`; routes `set-role`, `set-name`, `set-media` removed.

- [ ] **Step 1: Write the failing test** — `tests/AdminUsersPageTest.php`:

```php
<?php
// wcma-calculator/tests/AdminUsersPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-users.php';

use PHPUnit\Framework\TestCase;

final class AdminUsersPageTest extends TestCase
{
    private function users(): array {
        return [
            ['id' => 1, 'email' => 'boss@example.com', 'name' => 'Site Admin', 'role' => 'admin', 'password_hash' => 'x', 'google_id' => 'g',
             'active' => 1, 'is_media' => 0, 'created_at' => '2026-09-01 10:00:00'],
            ['id' => 3, 'email' => 'ivy@example.com', 'name' => 'Ivy', 'role' => 'inspector', 'password_hash' => 'x', 'google_id' => null,
             'active' => 0, 'is_media' => 1, 'created_at' => '2026-09-02 10:00:00'],
        ];
    }

    public function testSaveRules(): void
    {
        $admin = ['role' => 'admin'];
        $user = ['role' => 'user'];
        $this->assertSame('Cannot change the role of the last remaining admin.', adminUserSaveError($admin, 'Site Admin', 'inspector', 1));
        $this->assertNull(adminUserSaveError($admin, 'Site Admin', 'inspector', 2));
        $this->assertSame('Enter a name of 100 characters or fewer.', adminUserSaveError($user, '', 'user', 1));
        $this->assertSame('Enter a name of 100 characters or fewer.', adminUserSaveError($user, str_repeat('a', 101), 'user', 1));
        $this->assertSame('Choose a role.', adminUserSaveError($user, 'Jo Lee', 'boss', 1));
        $this->assertStringStartsWith('Add a first and last name before giving this account the inspector role.', adminUserSaveError($user, 'Ivy', 'inspector', 1));
        $this->assertNull(adminUserSaveError($user, 'Ivy', 'user', 1));
        $this->assertNull(adminUserSaveError($user, 'Ivy Inspector', 'inspector', 1));
        $this->assertSame('Ivy Inspector', adminNormalizeName("  Ivy \t Inspector "));
    }

    public function testRowsAreReadOnlyWithOneEditButtonAndModalsSitAfterTheTable(): void
    {
        $html = renderUsersPageHtml($this->users(), [1 => 4], 'tok', null, null);
        $this->assertSame(2, substr_count($html, 'data-dialog-open="user-dialog-'));
        $this->assertSame(2, substr_count($html, '<dialog '));
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
        $this->assertSame(2, substr_count($html, 'action="admin.php?action=user-save"'));
        $this->assertStringNotContainsString('action=set-', $html);
        $this->assertStringContainsString('<tr id="user-3" data-role="inspector">', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('Password · Google', $html);
    }

    public function testChipsAreSpansNotCellClasses(): void
    {
        $html = renderUsersPageHtml($this->users(), [], 'tok', null, null);
        $this->assertDoesNotMatchRegularExpression('/<td[^>]*class="badge-/', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--ink">Admin</span>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--pending">Media</span>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Needs first &amp; last name</span>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Inactive</span>', $html);
    }

    public function testModalSavesNameRoleAndMediaTogetherAndKeepsDeactivateApart(): void
    {
        $html = adminUserDialogHtml($this->users()[0], 'tok', null);
        $save = substr($html, strpos($html, 'action="admin.php?action=user-save"'));
        $save = substr($save, 0, strpos($save, '</form>'));
        $this->assertStringContainsString('name="name" value="Site Admin"', $save);
        $this->assertStringContainsString('name="role" value="admin" checked', $save);
        $this->assertStringContainsString('name="is_media" value="1">', $save);   // not ticked
        $this->assertStringNotContainsString('Deactivate', $save);
        $this->assertStringContainsString('<section class="admin-dialog-danger"><h3>Deactivate this account</h3>', $html);
        $this->assertStringContainsString('action="admin.php?action=deactivate" data-confirm="Deactivate boss@example.com?', $html);
        $inactive = adminUserDialogHtml($this->users()[1], 'tok', null);
        $this->assertStringContainsString('action="admin.php?action=activate"', $inactive);
        $this->assertStringContainsString('name="is_media" value="1" checked', $inactive);
    }

    public function testAnErrorReopensThatUsersModalWithTheMessageInside(): void
    {
        $html = renderUsersPageHtml($this->users(), [], 'tok', ['type' => 'error', 'message' => 'Choose a role.'], '3');
        $this->assertSame(1, substr_count($html, 'data-open-on-load'));
        $this->assertMatchesRegularExpression('/id="user-dialog-3"[^>]*data-open-on-load>.*Choose a role\./s', $html);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php phpunit.phar tests/AdminUsersPageTest.php` → FAIL (file not found).

- [ ] **Step 3: Implement** — `admin-users.php`:

```php
<?php
// wcma-calculator/admin-users.php
//
// Admin: Users & roles (admin desktop UX spec 2026-09-29 §3). A read-only list with one Edit modal per
// user; the modal saves name, role and media access together. Loaded by admin.php, which has already
// checked the admin role; POST handlers are reached through adminRequirePost() (CSRF).

/** A name as stored: trimmed, inner whitespace collapsed. */
function adminNormalizeName(string $name): string {
    return trim((string)preg_replace('/\s+/', ' ', $name));
}

/** Why a user-save can't go ahead, or null. $adminCount counts every admin account. Pure. */
function adminUserSaveError(array $target, string $name, string $role, int $adminCount): ?string {
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100) return 'Enter a name of 100 characters or fewer.';
    if (!isset(ROLE_LEVELS[$role])) return 'Choose a role.';
    if ($target['role'] === 'admin' && $role !== 'admin' && $adminCount <= 1) return 'Cannot change the role of the last remaining admin.';
    if ($role !== 'user' && !userHasFirstAndLastName($name)) {
        return 'Add a first and last name before giving this account the ' . $role . ' role. Review emails name the inspector.';
    }
    return null;
}

function handleUsersList(PDO $pdo): void {
    $users = db_get_all_users($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_column($users, 'id'));
    $body = renderUsersPageHtml($users, db_count_submissions_by_user($pdo), generateCsrfToken(), $place['dialog'], $edit);
    adminRenderPage('Users & roles', 'users', $body, $place['top'],
        '<script src="' . hubAsset('js/table-tools.js') . '"></script><script>'
        . "WcmaTableTools.enableSearch(document.getElementById('users-search'), document.getElementById('users-table'));"
        . "WcmaTableTools.enableSort(document.getElementById('users-table'));"
        . "WcmaTableTools.enableFilter(document.getElementById('users-role-filter'), document.getElementById('users-table'), 'role');"
        . '</script>');
}

function handleUserSave(PDO $pdo, int $id): void {
    $target = db_find_user_by_id($pdo, $id);
    if ($target === null) {
        setFlash('Choose a valid user.', 'error');
        adminRedirect('admin.php?action=users');
    }
    $name = adminNormalizeName((string)($_POST['name'] ?? ''));
    $role = (string)($_POST['role'] ?? '');
    $error = adminUserSaveError($target, $name, $role, db_count_admins($pdo));
    if ($error !== null) {
        setFlash($error, 'error');
        adminRedirect('admin.php?action=users&edit=' . $id);
    }
    $pdo->beginTransaction();
    if ($name !== (string)$target['name']) db_set_user_name($pdo, $id, $name);
    db_set_user_role($pdo, $id, $role);
    db_set_user_media($pdo, $id, ($_POST['is_media'] ?? '') === '1');
    $pdo->commit();
    setFlash('Saved ' . $name . '. Role and media changes apply the next time they sign in.', 'success');
    adminRedirect('admin.php?action=users#user-' . $id);
}

function handleSetActive(PDO $pdo, int $id, bool $active): void {
    if (!$active && db_count_active_admins($pdo) <= 1) {
        $target = db_find_user_by_id($pdo, $id);
        if ($target && $target['role'] === 'admin') {
            setFlash('Cannot deactivate the last remaining active admin.', 'error');
            adminRedirect('admin.php?action=users&edit=' . $id);
        }
    }
    db_set_user_active($pdo, $id, $active);
    setFlash($active ? 'User reactivated.' : 'User deactivated.', 'success');
    adminRedirect('admin.php?action=users#user-' . $id);
}

/** Role, media and missing-name chips for one user. */
function adminUserChips(array $u): string {
    $role = (string)$u['role'];
    $out = $role === 'admin' ? adminChip('Admin', 'ink') : ($role === 'inspector' ? adminChip('Inspector', 'info') : '<span>User</span>');
    if ((int)($u['is_media'] ?? 0) === 1) $out .= adminChip('Media', 'pending');
    if ($role !== 'user' && !userHasFirstAndLastName((string)$u['name'])) $out .= adminChip('Needs first & last name', 'fail');
    return '<span class="admin-chips">' . $out . '</span>';
}

/** The Edit modal for one user: name, role, media in one form; Deactivate/Reactivate below it. Pure. */
function adminUserDialogHtml(array $u, string $csrf, ?array $flash): string {
    $id = (int)$u['id'];
    $f = 'user-' . $id;
    $hidden = adminCsrfField($csrf) . '<input type="hidden" name="id" value="' . $id . '">';
    $roles = '';
    foreach (array_keys(ROLE_LEVELS) as $r) {
        $roles .= '<label><input type="radio" name="role" value="' . h($r) . '"' . ($u['role'] === $r ? ' checked' : '') . '> ' . h(ucfirst($r)) . '</label>';
    }
    $form = '<form method="post" action="admin.php?action=user-save" class="admin-form">' . $hidden
        . adminField($f . '-name', 'Name', '<input type="text" id="' . $f . '-name" name="name" value="' . h((string)$u['name']) . '" maxlength="100" required>', true)
        . '<fieldset class="radio-row admin-form-wide"><legend>Role</legend>' . $roles . '</fieldset>'
        . '<p class="form-hint admin-form-wide">Inspectors and admins need a first and last name. Role changes apply the next time they sign in.</p>'
        . '<label class="admin-form-wide"><input type="checkbox" name="is_media" value="1"' . ((int)($u['is_media'] ?? 0) === 1 ? ' checked' : '') . '> Media staff (can use the Media section)</label>'
        . adminDialogActions('Save')
        . '</form>';
    $danger = (int)$u['active'] === 1
        ? adminDangerHtml('Deactivate this account', 'They can\'t sign in until an admin reactivates the account.',
            '<form method="post" action="admin.php?action=deactivate" data-confirm="Deactivate ' . h((string)$u['email']) . '? They won\'t be able to sign in until reactivated.">'
            . $hidden . '<button type="submit" class="btn btn-secondary">Deactivate</button></form>')
        : adminDangerHtml('Reactivate this account', 'They can sign in again straight away.',
            '<form method="post" action="admin.php?action=activate">' . $hidden . '<button type="submit" class="btn btn-secondary">Reactivate</button></form>');
    $label = (string)$u['name'] !== '' ? (string)$u['name'] : (string)$u['email'];
    return adminDialogHtml('user-dialog-' . $id, 'Edit ' . $label, $form . $danger, $flash, (string)$u['email']);
}

/** The Users & roles page body: toolbar, read-only table, then one modal per user. Pure. */
function renderUsersPageHtml(array $users, array $counts, string $csrf, ?array $dialogFlash, ?string $edit): string {
    $out = '';
    if ($users) {
        $out .= '<div class="admin-toolbar"><div class="admin-toolbar-filters">'
            . '<input type="search" id="users-search" class="table-search" placeholder="Search by name or email" aria-label="Search users">'
            . '<select id="users-role-filter" class="table-filter" aria-label="Filter by role"><option value="">All roles</option>'
            . '<option value="admin">Admin</option><option value="inspector">Inspector</option><option value="user">User</option></select>'
            . '</div></div>';
    }
    $out .= '<table class="data-table admin-table" id="users-table"><thead><tr>'
        . '<th data-sort data-sort-type="text">Name</th><th data-sort data-sort-type="text">Role</th><th>Sign-in</th>'
        . '<th data-sort data-sort-type="number">Submissions</th><th data-sort data-sort-type="text">Status</th>'
        . '<th data-sort data-sort-type="date">Joined</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = '';
    foreach ($users as $u) {
        $id = (int)$u['id'];
        $name = (string)$u['name'] !== '' ? (string)$u['name'] : (string)$u['email'];
        $signIn = implode(' · ', array_filter([$u['password_hash'] ? 'Password' : '', $u['google_id'] ? 'Google' : '']));
        $out .= '<tr id="user-' . $id . '" data-role="' . h((string)$u['role']) . '">'
            . '<td data-label="Name" data-sort-value="' . h(mb_strtolower($name, 'UTF-8')) . '"><div><strong>' . h($name) . '</strong>'
            . '<span class="admin-sub">' . h((string)$u['email']) . '</span></div></td>'
            . '<td data-label="Role">' . adminUserChips($u) . '</td>'
            . '<td data-label="Sign-in">' . h($signIn !== '' ? $signIn : '—') . '</td>'
            . '<td data-label="Submissions">' . (int)($counts[$id] ?? 0) . '</td>'
            . '<td data-label="Status">' . ((int)$u['active'] === 1 ? adminChip('Active', 'ok') : adminChip('Inactive', 'fail')) . '</td>'
            . '<td data-label="Joined" data-sort-value="' . h((string)$u['created_at']) . '">' . h(date('M j, Y', strtotime((string)$u['created_at']))) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('user-dialog-' . $id, $name) . '</td></tr>';
        $dialogs .= adminUserDialogHtml($u, $csrf, $edit === (string)$id ? $dialogFlash : null);
    }
    $out .= '</tbody></table><p class="no-results-message" hidden>No users match your search.</p>';
    return $out . '<div class="admin-dialogs">' . $dialogs . '</div>';
}
```

- [ ] **Step 4: Route it** — in `admin.php`:
  - After `require __DIR__ . '/admin-season-links.php';` add:

```php
require __DIR__ . '/admin-ui.php';
require __DIR__ . '/admin-users.php';
```

  - Replace the three cases `set-role`, `set-name`, `set-media` with:

```php
    case 'user-save':
        adminRequirePost('admin.php?action=users');
        handleUserSave($pdo, $postId);
        break;
```

  - Delete `handleUsersList`, `handleSetRole`, `handleSetName`, `handleSetMedia`, `handleSetActive` and `renderUsersPage` from `admin.php` (they now live in `admin-users.php`, rewritten as above). Update the header comment to: `// wcma-calculator/admin.php — the Admin back office router (spec §5; admin desktop UX spec 2026-09-29). Each tab's handlers and page live in admin-<tab>.php.`

- [ ] **Step 5: Update source tests.**
  - `tests/MediaSourceTest.php` — replace `testAdminCanSetTheMediaFlagByCsrfCheckedPost` body with:

```php
        $admin = $this->src('admin.php');
        $this->assertMatchesRegularExpression("/case 'user-save':\s*adminRequirePost\('admin.php\?action=users'\);\s*handleUserSave\(\\\$pdo, \\\$postId\);/", $admin);
        $users = $this->src('admin-users.php');
        $this->assertStringContainsString("db_set_user_media(\$pdo, \$id, (\$_POST['is_media'] ?? '') === '1');", $users);
        $this->assertStringContainsString('name="is_media" value="1"', $users);
```

  - `tests/AdminSourceTest.php`:
    - In `testBackOfficePagesUseTheAdminTabs`, change the `'admin.php' => [...]` entry to `'admin.php' => ["adminSubnavHtml('events')", "adminSubnavHtml('settings')"]` and add `'admin-users.php' => ["adminRenderPage('Users & roles', 'users'"],` and `'admin-ui.php' => ["adminSubnavHtml(\$tab)"],`.
    - Delete `testDeactivateAndReactivateAreSecondaryButtons` (covered by `AdminUsersPageTest`).
    - Add:

```php
    public function testUserSaveChecksEverythingBeforeWritingAnything(): void
    {
        $body = $this->body('admin-users.php', 'handleUserSave');
        $check = strpos($body, 'adminUserSaveError(');
        foreach (['db_set_user_name(', 'db_set_user_role(', 'db_set_user_media('] as $write) {
            $this->assertLessThan(strpos($body, $write), $check, $write);
        }
        $this->assertStringContainsString("adminRedirect('admin.php?action=users&edit=' . \$id);", $body);
        foreach (["case 'set-role':", "case 'set-name':", "case 'set-media':"] as $gone) {
            $this->assertStringNotContainsString($gone, $this->src('admin.php'));
        }
    }
```

- [ ] **Step 6: Run** — `php phpunit.phar` → all green.
- [ ] **Step 7: Look at it** — run the scratch server (see Task 7 Step 1) and screenshot `admin.php?action=users` at 1440 and 390; open a modal by hand in a browser if possible. Rows should be one line tall on desktop, cards on phone.
- [ ] **Step 8: Commit** — `git add -A wcma-calculator && git commit -m "feat(admin): Users & roles as a read-only list with an edit modal"`

---

### Task 4: Events — list, add modal, edit modal

**Files:**
- Create: `wcma-calculator/admin-events.php`, `wcma-calculator/tests/AdminEventsPageTest.php`
- Modify: `wcma-calculator/admin.php`, `wcma-calculator/tests/AdminSourceTest.php`

**Interfaces:**
- Consumes: Task 1 helpers; `eventClubOptions(array $clubs, array $event, array $iceCodes)` (clubs-lib.php), `iceClubCodes()`, `iceEventFields()` (ice-rules.php).
- Produces: `adminEventClubCodes(PDO, ?array): array` (moved), `adminEventFieldsHtml(string $prefix, array $e, array $clubs): string`, `renderEventsPageHtml(array $events, array $going, array $clubs, string $csrf, ?array $dialogFlash, ?string $edit): string`, `handleEventsList`, `handleEventCreate`, `handleEventUpdate`, `handleEventSetActive`. Route `event-club` and `handleEventClub` removed.

- [ ] **Step 1: Write the failing test** — `tests/AdminEventsPageTest.php`:

```php
<?php
// wcma-calculator/tests/AdminEventsPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../clubs-lib.php';
require_once __DIR__ . '/../ice-rules.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-events.php';

use PHPUnit\Framework\TestCase;

final class AdminEventsPageTest extends TestCase
{
    private function clubs(): array {
        return [
            ['code' => 'ESCC', 'name' => 'Edmonton', 'msr_url' => '', 'active' => 0],
            ['code' => 'NASCC', 'name' => 'Northern Alberta', 'msr_url' => '', 'active' => 1],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 1],
        ];
    }

    private function events(): array {
        return [
            ['id' => 7, 'name' => 'Fire on Ice', 'event_date' => '2026-11-19', 'location' => 'Lake Shirley', 'discipline' => 'ice', 'host_club' => 'NASCC', 'active' => 1],
            ['id' => 8, 'name' => 'Fall Sprint', 'event_date' => '2026-10-15', 'location' => null, 'discipline' => 'summer', 'host_club' => 'ESCC', 'active' => 0],
        ];
    }

    public function testListHasAnAddButtonAndOneEditButtonPerEventWithModalsAfterTheTable(): void
    {
        $html = renderEventsPageHtml($this->events(), [7 => 1], $this->clubs(), 'tok', null, null);
        $this->assertStringContainsString('data-dialog-open="event-dialog-new"', $html);
        $this->assertSame(2, substr_count($html, 'class="btn btn-secondary admin-edit"'));
        $this->assertSame(3, substr_count($html, '<dialog '));
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
        $this->assertSame(1, substr_count($html, 'action="admin.php?action=event-create"'));
        $this->assertSame(2, substr_count($html, 'action="admin.php?action=event-update"'));
        $this->assertStringNotContainsString('action=event-club', $html);
        $this->assertStringContainsString('<th>Going</th>', $html);
        $this->assertStringContainsString('1 car', $html);
    }

    public function testRowShowsIceAndHostClubWithoutRepeatingIt(): void
    {
        $html = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--pending">Ice</span>', $html);
        $this->assertStringContainsString('<td data-label="Host club">NASCC</td>', $html);
        $this->assertStringNotContainsString('Ice · NASCC', $html);
        $this->assertStringContainsString('<td data-label="Location">—</td>', $html);
        $this->assertDoesNotMatchRegularExpression('/<td[^>]*class="badge-/', $html);
    }

    public function testEditModalIsFilledInAndKeepsAnInactiveClub(): void
    {
        $html = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', null, null);
        $sprint = substr($html, strpos($html, 'id="event-dialog-8"'));
        $sprint = substr($sprint, 0, strpos($sprint, '</dialog>'));
        $this->assertStringContainsString('name="name" required value="Fall Sprint"', $sprint);
        $this->assertStringContainsString('name="event_date" required value="2026-10-15"', $sprint);
        $this->assertStringContainsString('name="discipline" value="summer" checked', $sprint);
        $this->assertStringContainsString('<option value="ESCC" selected>ESCC — Edmonton (inactive)</option>', $sprint);
        $this->assertStringContainsString('action="admin.php?action=event-activate"', $sprint);
        $ice = substr($html, strpos($html, 'id="event-dialog-7"'));
        $ice = substr($ice, 0, strpos($ice, '</dialog>'));
        $this->assertStringContainsString('name="discipline" value="ice" checked', $ice);
        $this->assertStringContainsString('data-confirm="Deactivate Fire on Ice?', $ice);
    }

    public function testAddModalStartsBlankAsSummerWithNoClub(): void
    {
        $html = renderEventsPageHtml([], [], $this->clubs(), 'tok', null, null);
        $add = substr($html, strpos($html, 'id="event-dialog-new"'));
        $this->assertStringContainsString('name="discipline" value="summer" checked', $add);
        $this->assertStringContainsString('<option value="" selected>No host club</option>', $add);
        $this->assertStringNotContainsString('value="ESCC"', $add);   // inactive clubs can't host new events
        $this->assertStringContainsString('No events yet.', $html);
    }

    public function testAnAddErrorReopensTheAddModal(): void
    {
        $html = renderEventsPageHtml($this->events(), [], $this->clubs(), 'tok', ['type' => 'error', 'message' => 'Event name and date are required.'], 'new');
        $this->assertSame(1, substr_count($html, 'data-open-on-load'));
        $this->assertMatchesRegularExpression('/id="event-dialog-new"[^>]*data-open-on-load>.*Event name and date are required\./s', $html);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php phpunit.phar tests/AdminEventsPageTest.php` → FAIL.

- [ ] **Step 3: Implement** — `admin-events.php` (the create/update/active handlers are moved from `admin.php` with two changes: errors go through `adminRedirect(... &edit=…)`, and update checks the event exists first):

```php
<?php
// wcma-calculator/admin-events.php
//
// Admin: Events (admin desktop UX spec 2026-09-29 §4). A read-only list; Add event and each row's Edit
// open a modal with name, date, location, discipline and host club. Loaded by admin.php, which has
// already checked the admin role; POST handlers are reached through adminRequirePost() (CSRF).

/** The club codes an event may take: the active clubs, plus the one it already has (even if inactive). */
function adminEventClubCodes(PDO $pdo, ?array $event): array {
    $codes = array_column(db_get_clubs($pdo, true), 'code');
    $current = (string)($event['host_club'] ?? '');
    if ($current !== '' && !in_array($current, $codes, true)) $codes[] = $current;
    return $codes;
}

function handleEventsList(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_merge(['new'], array_column($events, 'id')));
    $body = renderEventsPageHtml($events, db_count_event_plans($pdo), db_get_clubs($pdo), generateCsrfToken(), $place['dialog'], $edit);
    adminRenderPage('Events', 'events', $body, $place['top']);
}

function handleEventCreate(PDO $pdo): void {
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        adminRedirect('admin.php?action=events&edit=new');
    }
    $fields = iceEventFields($_POST, array_column(db_get_clubs($pdo, true), 'code'));
    if (!$fields['ok']) {
        setFlash((string)$fields['error'], 'error');
        adminRedirect('admin.php?action=events&edit=new');
    }
    db_create_event($pdo, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
    setFlash('Event created.', 'success');
    adminRedirect('admin.php?action=events');
}

function handleEventUpdate(PDO $pdo, int $id): void {
    $current = db_get_event($pdo, $id);
    if ($current === null) {
        setFlash('Event not found.', 'error');
        adminRedirect('admin.php?action=events');
    }
    $name = trim($_POST['name'] ?? '');
    $date = trim($_POST['event_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    if ($name === '' || $date === '') {
        setFlash('Event name and date are required.', 'error');
        adminRedirect('admin.php?action=events&edit=' . $id);
    }

    // A POST without "discipline" (a stale form or script) keeps the event's discipline and club.
    $disciplineInput = $_POST;
    if (!array_key_exists('discipline', $disciplineInput)) {
        $disciplineInput['discipline'] = $current['discipline'] ?? 'summer';
        if (!array_key_exists('host_club', $disciplineInput)) {
            $disciplineInput['host_club'] = $current['host_club'] ?? '';
        }
    }
    $fields = iceEventFields($disciplineInput, adminEventClubCodes($pdo, db_get_event($pdo, $id)));
    if (!$fields['ok']) {
        setFlash((string)$fields['error'], 'error');
        adminRedirect('admin.php?action=events&edit=' . $id);
    }
    db_update_event($pdo, $id, $name, $date, $location !== '' ? $location : null, $fields['discipline'], $fields['club']);
    setFlash('Event updated.', 'success');
    adminRedirect('admin.php?action=events');
}

function handleEventSetActive(PDO $pdo, int $id, bool $active): void {
    db_set_event_active($pdo, $id, $active);
    setFlash($active ? 'Event reactivated.' : 'Event deactivated.', 'success');
    adminRedirect('admin.php?action=events');
}

/** The add/edit fields for one event ($e = [] for a new one). Pure. */
function adminEventFieldsHtml(string $p, array $e, array $clubs): string {
    $discipline = ($e['discipline'] ?? 'summer') === 'ice' ? 'ice' : 'summer';
    // Every active club (plus this event's current one): the ice-only rule is checked on save, so an
    // admin can switch discipline and club in one go.
    $options = '';
    foreach (eventClubOptions($clubs, ['discipline' => 'summer', 'host_club' => $e['host_club'] ?? null], iceClubCodes()) as $o) {
        $options .= '<option value="' . h($o['code']) . '"' . ($o['selected'] ? ' selected' : '') . '>' . h($o['label']) . '</option>';
    }
    $radio = static fn(string $v, string $label): string =>
        '<label><input type="radio" name="discipline" value="' . $v . '"' . ($discipline === $v ? ' checked' : '') . '> ' . $label . '</label>';
    return adminField($p . '-name', 'Name', '<input type="text" id="' . h($p) . '-name" name="name" required value="' . h((string)($e['name'] ?? '')) . '">', true)
        . adminField($p . '-date', 'Date', '<input type="date" id="' . h($p) . '-date" name="event_date" required value="' . h(substr((string)($e['event_date'] ?? ''), 0, 10)) . '">')
        . adminField($p . '-location', 'Location', '<input type="text" id="' . h($p) . '-location" name="location" value="' . h((string)($e['location'] ?? '')) . '">')
        . '<fieldset class="radio-row"><legend>Discipline</legend>' . $radio('summer', 'Summer') . $radio('ice', 'Ice') . '</fieldset>'
        . adminField($p . '-club', 'Host club', '<select id="' . h($p) . '-club" name="host_club">' . $options . '</select>')
        . '<p class="form-hint admin-form-wide">Ice events need NASCC or WSCC. Add other clubs on the <a href="admin.php?action=clubs">Clubs</a> tab.</p>';
}

/** The Events page body: Add button, read-only table, then the add modal and one modal per event. Pure. */
function renderEventsPageHtml(array $events, array $going, array $clubs, string $csrf, ?array $dialogFlash, ?string $edit): string {
    $out = '<div class="admin-toolbar">' . adminAddButton('event-dialog-new', 'Add event') . '</div>'
        . '<table class="data-table admin-table" id="events-table"><thead><tr><th>Date</th><th>Name</th><th>Location</th>'
        . '<th>Host club</th><th>Going</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = adminDialogHtml('event-dialog-new', 'Add an event',
        '<form method="post" action="admin.php?action=event-create" class="admin-form">' . adminCsrfField($csrf)
        . adminEventFieldsHtml('event-new', [], $clubs) . adminDialogActions('Add event') . '</form>',
        $edit === 'new' ? $dialogFlash : null);
    if (!$events) {
        $out .= '<tr><td colspan="7" class="empty-row">No events yet.</td></tr>';
    }
    foreach ($events as $e) {
        $id = (int)$e['id'];
        $n = (int)($going[$id] ?? 0);
        $isIce = ($e['discipline'] ?? 'summer') === 'ice';
        $club = (string)($e['host_club'] ?? '');
        $out .= '<tr id="event-' . $id . '">'
            . '<td data-label="Date">' . h(date('M j, Y', strtotime((string)$e['event_date']))) . '</td>'
            . '<td data-label="Name">' . h((string)$e['name']) . ($isIce ? ' ' . adminChip('Ice', 'pending') : '') . '</td>'
            . '<td data-label="Location">' . h((string)($e['location'] ?? '') !== '' ? (string)$e['location'] : '—') . '</td>'
            . '<td data-label="Host club">' . h($club !== '' ? $club : '—') . '</td>'
            . '<td data-label="Going">' . $n . ' ' . ($n === 1 ? 'car' : 'cars') . '</td>'
            . '<td data-label="Status">' . ((int)$e['active'] === 1 ? adminChip('Active', 'ok') : adminChip('Inactive', 'fail')) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('event-dialog-' . $id, (string)$e['name']) . '</td></tr>';
        $hidden = adminCsrfField($csrf) . '<input type="hidden" name="id" value="' . $id . '">';
        $danger = (int)$e['active'] === 1
            ? adminDangerHtml('Deactivate this event', 'Competitors can\'t tag it or pick it for new tech sheets. Nothing is deleted.',
                '<form method="post" action="admin.php?action=event-deactivate" data-confirm="Deactivate ' . h((string)$e['name'])
                . '? Competitors won\'t be able to tag it or pick it for new tech sheets.">' . $hidden
                . '<button type="submit" class="btn btn-secondary">Deactivate</button></form>')
            : adminDangerHtml('Reactivate this event', 'Competitors can tag it and pick it for tech sheets again.',
                '<form method="post" action="admin.php?action=event-activate">' . $hidden
                . '<button type="submit" class="btn btn-secondary">Reactivate</button></form>');
        $dialogs .= adminDialogHtml('event-dialog-' . $id, 'Edit ' . $e['name'],
            '<form method="post" action="admin.php?action=event-update" class="admin-form">' . $hidden
            . adminEventFieldsHtml('event-' . $id, $e, $clubs) . adminDialogActions('Save') . '</form>' . $danger,
            $edit === (string)$id ? $dialogFlash : null);
    }
    return $out . '</tbody></table><div class="admin-dialogs">' . $dialogs . '</div>';
}
```

- [ ] **Step 4: Route it** — in `admin.php`: add `require __DIR__ . '/admin-events.php';` after the `admin-users.php` require; delete the `case 'event-club':` block; delete `adminEventClubCodes`, `handleEventClub`, `handleEventsList`, `handleEventCreate`, `handleEventUpdate`, `handleEventSetActive`, `renderEventsPage` from `admin.php`.

- [ ] **Step 5: Update `tests/AdminSourceTest.php`:**
  - `testBackOfficePagesUseTheAdminTabs`: `'admin.php' => ["adminSubnavHtml('settings')"]`; add `'admin-events.php' => ["adminRenderPage('Events', 'events'"],`.
  - `testEventsListShowsHowManyCarsAreGoing`: read `admin-events.php` instead of `admin.php`.
  - `testEventFormHasDisciplineAndHostClub`: read `admin-events.php`.
  - `testEventUpdateKeepsCurrentDisciplineWhenPostOmitsIt`: `$this->body('admin-events.php', 'handleEventUpdate')`.
  - `testClubsTabIsRoutedAndEventsTakeClubsFromTheList`: set `$src = $this->src('admin.php') . $this->src('admin-events.php');`, and drop `"case 'event-club':"` from the needle list. Add `$this->assertStringNotContainsString("case 'event-club':", $this->src('admin.php'));`.
  - `testEventClubHandlersAcceptTheEventsCurrentClub`: replace body with:

```php
        $this->assertStringContainsString('eventClubOptions(', $this->src('admin-events.php'));
        $this->assertStringContainsString('adminEventClubCodes($pdo, ', $this->body('admin-events.php', 'handleEventUpdate'));
```

- [ ] **Step 6: Run** — `php phpunit.phar` → all green.
- [ ] **Step 7: Commit** — `git add -A wcma-calculator && git commit -m "feat(admin): Events list with add and edit modals; events can now be edited"`

---

### Task 5: Clubs — table with add and edit modals

**Files:**
- Modify: `wcma-calculator/admin-clubs.php`, `wcma-calculator/tests/AdminClubsPageTest.php`, `wcma-calculator/tests/AdminSourceTest.php`

**Interfaces:**
- Consumes: Task 1 helpers; `clubValidate()`, `db_get_club()`, `db_create_club()`, `db_update_club()`, `db_get_clubs()`.
- Produces: `renderClubsPageHtml(array $clubs, string $csrf, ?array $dialogFlash = null, ?string $edit = null): string`.

- [ ] **Step 1: Replace the test** — `tests/AdminClubsPageTest.php`:

```php
<?php
// wcma-calculator/tests/AdminClubsPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-clubs.php';

use PHPUnit\Framework\TestCase;

final class AdminClubsPageTest extends TestCase
{
    private function clubs(): array {
        return [
            ['code' => 'NASCC', 'name' => 'Northern <Alberta>', 'msr_url' => 'https://msr.example/n', 'active' => 1],
            ['code' => 'WSCC', 'name' => 'Winnipeg', 'msr_url' => '', 'active' => 0],
        ];
    }

    public function testTableListsClubsWithOneEditEachAndModalsAfterIt(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok');
        $this->assertStringContainsString('Northern &lt;Alberta&gt;', $html);
        $this->assertStringContainsString('<a class="admin-link" href="https://msr.example/n" target="_blank" rel="noopener">Open ↗</a>', $html);
        $this->assertStringContainsString('<td data-label="MotorsportReg">—</td>', $html);
        $this->assertStringContainsString('<span class="admin-chip admin-chip--fail">Inactive</span>', $html);
        $this->assertSame(2, substr_count($html, 'class="btn btn-secondary admin-edit"'));
        $this->assertStringContainsString('data-dialog-open="club-dialog-new"', $html);
        $this->assertSame(3, substr_count($html, 'action="admin.php?action=club-save"'));
        $this->assertLessThan(strpos($html, '<dialog '), strpos($html, '</table>'));
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
    }

    public function testEditModalKeepsTheCodeFixedAndNamesTheActiveBox(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok');
        $wscc = substr($html, strpos($html, 'id="club-dialog-wscc"'));
        $wscc = substr($wscc, 0, strpos($wscc, '</dialog>'));
        $this->assertStringContainsString('Edit WSCC', $wscc);
        $this->assertStringContainsString('<input type="hidden" name="code" value="WSCC">', $wscc);
        $this->assertStringContainsString('type="url"', $wscc);
        $this->assertMatchesRegularExpression('/name="active" value="1"(?![^>]*checked)[^>]*>\s*Active — can host new events/', $wscc);
        $new = substr($html, strpos($html, 'id="club-dialog-new"'));
        $this->assertStringContainsString('<input type="hidden" name="is_new" value="1">', $new);
        $this->assertStringContainsString('name="code"', $new);
    }

    public function testAnErrorReopensThatClubsModal(): void
    {
        $html = renderClubsPageHtml($this->clubs(), 'tok', ['type' => 'error', 'message' => 'Enter the club name.'], 'NASCC');
        $this->assertMatchesRegularExpression('/id="club-dialog-nascc"[^>]*data-open-on-load>.*Enter the club name\./s', $html);
        $this->assertSame(1, substr_count($html, 'data-open-on-load'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php phpunit.phar tests/AdminClubsPageTest.php` → FAIL.

- [ ] **Step 3: Implement** — replace `handleClubsList`, `handleClubSave`, `renderClubsPageHtml` and `renderClubsPage` in `admin-clubs.php` with:

```php
function handleClubsList(PDO $pdo): void {
    $clubs = db_get_clubs($pdo);
    $edit = adminEditTarget($_GET);
    $place = adminFlashPlacement(getFlash(), $edit, array_merge(['new'], array_column($clubs, 'code')));
    adminRenderPage('Clubs', 'clubs', renderClubsPageHtml($clubs, generateCsrfToken(), $place['dialog'], $edit), $place['top']);
}

function handleClubSave(PDO $pdo): void {
    $isNew = ($_POST['is_new'] ?? '') === '1';
    $v = clubValidate((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['msr_url'] ?? ''));
    if (!$isNew && db_get_club($pdo, $v['code']) === null) {
        setFlash('Club not found.', 'error');
        adminRedirect('admin.php?action=clubs');
    }
    $reopen = 'admin.php?action=clubs&edit=' . ($isNew ? 'new' : rawurlencode($v['code']));
    if (!$v['ok']) {
        setFlash((string)$v['error'], 'error');
        adminRedirect($reopen);
    }
    if ($isNew && db_get_club($pdo, $v['code']) !== null) {
        setFlash('A club with the code ' . $v['code'] . ' already exists.', 'error');
        adminRedirect($reopen);
    }
    if ($isNew) {
        db_create_club($pdo, $v['code'], $v['name'], $v['url']);
        setFlash('Club added.', 'success');
    } else {
        db_update_club($pdo, $v['code'], $v['name'], $v['url'], !empty($_POST['active']));
        setFlash('Club saved.', 'success');
    }
    adminRedirect('admin.php?action=clubs');
}

/** The page body: intro, Add club, a read-only table, then the add modal and one modal per club. Pure. */
function renderClubsPageHtml(array $clubs, string $csrf, ?array $dialogFlash = null, ?string $edit = null): string {
    $csrfField = adminCsrfField($csrf);
    $out = '<p class="admin-intro">Clubs host events. A club\'s MotorsportReg link is shown to competitors after they '
        . 'submit a tech sheet, so they can register for the event. NASCC and WSCC run the ice races.</p>'
        . '<div class="admin-toolbar">' . adminAddButton('club-dialog-new', 'Add club') . '</div>'
        . '<table class="data-table admin-table" id="clubs-table"><thead><tr><th>Code</th><th>Name</th><th>MotorsportReg</th>'
        . '<th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    $dialogs = adminDialogHtml('club-dialog-new', 'Add a club',
        '<form method="post" action="admin.php?action=club-save" class="admin-form">' . $csrfField
        . '<input type="hidden" name="is_new" value="1">'
        . adminField('club-new-code', 'Short code (letters, numbers or dashes)', '<input type="text" id="club-new-code" name="code" maxlength="12" required placeholder="ESCC">')
        . adminField('club-new-name', 'Name', '<input type="text" id="club-new-name" name="name" maxlength="120" required>')
        . adminField('club-new-url', 'MotorsportReg link (optional)', '<input type="url" id="club-new-url" name="msr_url" placeholder="https://">', true)
        . adminDialogActions('Add club') . '</form>',
        $edit === 'new' ? $dialogFlash : null);
    if (!$clubs) {
        $out .= '<tr><td colspan="5" class="empty-row">No clubs yet.</td></tr>';
    }
    foreach ($clubs as $c) {
        $code = (string)$c['code'];
        $id = 'club-' . strtolower($code);
        $url = (string)$c['msr_url'];
        $active = (int)$c['active'] === 1;
        $out .= '<tr id="' . h($id) . '"><td data-label="Code"><strong>' . h($code) . '</strong></td>'
            . '<td data-label="Name">' . h((string)$c['name']) . '</td>'
            . '<td data-label="MotorsportReg">' . ($url !== ''
                ? '<a class="admin-link" href="' . h($url) . '" target="_blank" rel="noopener">Open ↗</a>' : '—') . '</td>'
            . '<td data-label="Status">' . ($active ? adminChip('Active', 'ok') : adminChip('Inactive', 'fail')) . '</td>'
            . '<td class="admin-cell-actions">' . adminEditButton('club-dialog-' . strtolower($code), $code) . '</td></tr>';
        $dialogs .= adminDialogHtml('club-dialog-' . strtolower($code), 'Edit ' . $code,
            '<form method="post" action="admin.php?action=club-save" class="admin-form">' . $csrfField
            . '<input type="hidden" name="code" value="' . h($code) . '">'
            . adminField($id . '-name', 'Name', '<input type="text" id="' . h($id) . '-name" name="name" maxlength="120" required value="' . h((string)$c['name']) . '">', true)
            . adminField($id . '-url', 'MotorsportReg link (optional)', '<input type="url" id="' . h($id) . '-url" name="msr_url" placeholder="https://" value="' . h($url) . '">', true)
            . '<label class="admin-form-wide"><input type="checkbox" name="active" value="1"' . ($active ? ' checked' : '') . '> Active — can host new events</label>'
            . adminDialogActions('Save') . '</form>',
            $edit === $code ? $dialogFlash : null);
    }
    return $out . '</tbody></table><div class="admin-dialogs">' . $dialogs . '</div>';
}
```

  Update the file header comment to mention the modals (`// Admin: host clubs (clubs spec 2026-09-29 §1; admin desktop UX spec §5): a table with add and edit modals.`).

- [ ] **Step 4: Source test** — in `testBackOfficePagesUseTheAdminTabs` add `'admin-clubs.php' => ["adminRenderPage('Clubs', 'clubs'"],`.
- [ ] **Step 5: Run** — `php phpunit.phar` → all green.
- [ ] **Step 6: Commit** — `git add -A wcma-calculator && git commit -m "feat(admin): Clubs as a table with add and edit modals"`

---

### Task 6: Season links, Settings, Feedback shell

**Files:**
- Create: `wcma-calculator/admin-settings.php`, `wcma-calculator/tests/AdminSettingsLinksPageTest.php`
- Modify: `wcma-calculator/admin-season-links.php`, `wcma-calculator/admin-feedback.php`, `wcma-calculator/admin.php`, `wcma-calculator/tests/AdminSourceTest.php`

**Interfaces:**
- Consumes: Task 1 helpers.
- Produces: `renderSeasonLinksPageHtml(array $links, string $csrf): string`, `renderSettingsPageHtml(array $values, string $csrf): string`, `handleSettings`, `handleSettingsUpdate` (moved).

- [ ] **Step 1: Write the failing test** — `tests/AdminSettingsLinksPageTest.php`:

```php
<?php
// wcma-calculator/tests/AdminSettingsLinksPageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../admin-ui.php';
require_once __DIR__ . '/../admin-season-links.php';
require_once __DIR__ . '/../admin-settings.php';

use PHPUnit\Framework\TestCase;

final class AdminSettingsLinksPageTest extends TestCase
{
    public function testSeasonLinksEditInTheTableWithSaveAndRemoveApart(): void
    {
        $html = renderSeasonLinksPageHtml([
            ['id' => 4, 'label' => '2026 <Waiver>', 'url' => 'https://msr.example/w', 'sort_order' => 1, 'active' => 1],
        ], 'tok');
        $this->assertStringContainsString('class="data-table admin-table"', $html);
        $this->assertStringContainsString('<input form="link-4" type="url" name="url" value="https://msr.example/w"', $html);
        $this->assertStringContainsString('2026 &lt;Waiver&gt;', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-secondary">Save</button>', $html);
        $this->assertStringContainsString('data-confirm="Remove “2026 &lt;Waiver&gt;”? Competitors will stop seeing it."', $html);
        $this->assertStringContainsString('<button type="submit" class="link-button">Remove</button>', $html);
        $this->assertStringContainsString('<form method="post" action="admin.php?action=season-link-save" class="admin-form">', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testSeasonLinksEmptyStateAsksForTheFirstOne(): void
    {
        $this->assertStringContainsString('No links yet. Add one below.', renderSeasonLinksPageHtml([], 'tok'));
    }

    public function testSettingsGroupEmailAndNameUnderEachRecipient(): void
    {
        $html = renderSettingsPageHtml([
            'classing_recipient_email' => 'c@example.com', 'classing_recipient_name' => 'Classing',
            'tech_sheet_recipient_email' => 't@example.com', 'tech_sheet_recipient_name' => 'Tech',
            'feedback_recipient_email' => 'f@example.com', 'feedback_recipient_name' => 'Feedback <Desk>',
        ], 'tok');
        foreach (['Class calculator', 'Tech sheets', 'Feedback'] as $legend) {
            $this->assertStringContainsString('<legend>' . $legend . '</legend>', $html);
        }
        $this->assertSame(6, substr_count($html, '<label for="'));   // Email + Name per recipient
        $this->assertStringContainsString('name="feedback_recipient_name" value="Feedback &lt;Desk&gt;"', $html);
        $this->assertStringContainsString('class="detail-card admin-settings"', $html);
        $this->assertSame(1, substr_count($html, 'type="submit"'));
        $this->assertStringNotContainsString('— Email', $html);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php phpunit.phar tests/AdminSettingsLinksPageTest.php` → FAIL.

- [ ] **Step 3: Season links** — in `admin-season-links.php`, change the three `header('Location: admin.php?action=season-links'); exit;` pairs to `adminRedirect('admin.php?action=season-links');`, then replace `renderSeasonLinksPage` with:

```php
function handleSeasonLinksList(PDO $pdo): void {
    adminRenderPage('Season links', 'season-links', renderSeasonLinksPageHtml(db_get_season_links($pdo), generateCsrfToken()), getFlash());
}

/** The page body: links edited in the table (updated together each season), then an Add form. Pure. */
function renderSeasonLinksPageHtml(array $links, string $csrf): string {
    $csrfField = adminCsrfField($csrf);
    $out = '<p class="admin-intro">These links are shown to competitors as "This season on MotorsportReg". MotorsportReg gives each '
        . 'season\'s waiver and licences new web addresses, so update them at the start of every season.</p>'
        . '<table class="data-table admin-table"><thead><tr><th class="admin-col-order">Order</th><th>Label</th><th>Web address</th>'
        . '<th>Shown</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    if (!$links) {
        $out .= '<tr><td colspan="5" class="empty-row">No links yet. Add one below.</td></tr>';
    }
    foreach ($links as $l) {
        $id = (int)$l['id'];
        $fid = 'link-' . $id;
        $hidden = $csrfField . '<input type="hidden" name="id" value="' . $id . '">';
        $out .= '<tr>'
            . '<td class="admin-col-order" data-label="Order"><input form="' . $fid . '" type="number" name="sort_order" value="' . (int)$l['sort_order'] . '" aria-label="Order"></td>'
            . '<td data-label="Label"><input form="' . $fid . '" type="text" name="label" value="' . h((string)$l['label']) . '" maxlength="120" required aria-label="Label"></td>'
            . '<td data-label="Web address"><input form="' . $fid . '" type="url" name="url" value="' . h((string)$l['url']) . '" required aria-label="Web address"></td>'
            . '<td data-label="Shown"><input form="' . $fid . '" type="checkbox" name="active" value="1"' . ((int)$l['active'] === 1 ? ' checked' : '') . ' aria-label="Shown"></td>'
            . '<td class="admin-cell-actions"><div class="admin-row-actions">'
            . '<form id="' . $fid . '" method="post" action="admin.php?action=season-link-save">' . $hidden
            . '<button type="submit" class="btn btn-secondary">Save</button></form>'
            . '<form method="post" action="admin.php?action=season-link-delete" data-confirm="Remove “' . h((string)$l['label']) . '”? Competitors will stop seeing it.">'
            . $hidden . '<button type="submit" class="link-button">Remove</button></form>'
            . '</div></td></tr>';
    }
    $out .= '</tbody></table>'
        . '<section class="detail-card"><h2>Add a link</h2>'
        . '<form method="post" action="admin.php?action=season-link-save" class="admin-form">' . $csrfField
        . '<input type="hidden" name="id" value="0">'
        . adminField('new-label', 'Label', '<input type="text" id="new-label" name="label" maxlength="120" required placeholder="2026 Annual Waiver / Hardcard">')
        . adminField('new-url', 'Web address', '<input type="url" id="new-url" name="url" required placeholder="https://www.motorsportreg.com/events/…">')
        . adminField('new-sort', 'Order (lower shows first)', '<input type="number" id="new-sort" name="sort_order" value="' . count($links) . '">')
        . '<div class="admin-form-actions admin-form-wide"><button type="submit" class="btn btn-primary">Add link</button></div>'
        . '</form></section>';
    return $out;
}
```

- [ ] **Step 4: Settings** — create `admin-settings.php` with `handleSettings` and `handleSettingsUpdate` moved verbatim from `admin.php` (replace each `header('Location: admin.php?action=settings'); exit;` with `adminRedirect('admin.php?action=settings');`, and in `handleSettings` replace `renderSettingsPage($values, $csrf, $flash);` with `adminRenderPage('Settings', 'settings', renderSettingsPageHtml($values, $csrf), $flash);`), plus:

```php
<?php
// wcma-calculator/admin-settings.php
//
// Admin: Settings — where submissions and feedback are emailed (admin desktop UX spec 2026-09-29 §7).
// Loaded by admin.php, which has already checked the admin role; settings-update is CSRF-checked there.

// … handleSettings() and handleSettingsUpdate() moved from admin.php …

const ADMIN_SETTINGS_GROUPS = [
    ['legend' => 'Class calculator', 'id' => 'classing', 'email' => 'classing_recipient_email', 'name' => 'classing_recipient_name'],
    ['legend' => 'Tech sheets', 'id' => 'tech-sheet', 'email' => 'tech_sheet_recipient_email', 'name' => 'tech_sheet_recipient_name'],
    ['legend' => 'Feedback', 'id' => 'feedback', 'email' => 'feedback_recipient_email', 'name' => 'feedback_recipient_name'],
];

/** The Settings body: one card, a fieldset per recipient with Email and Name side by side. Pure. */
function renderSettingsPageHtml(array $values, string $csrf): string {
    $out = '<p class="admin-intro">Where class calculator submissions, tech sheet submissions and user feedback are emailed. '
        . 'People who submit a class calculation or a tech sheet always get their own copy too.</p>'
        . '<form method="post" action="admin.php?action=settings-update" class="detail-card admin-settings">' . adminCsrfField($csrf);
    foreach (ADMIN_SETTINGS_GROUPS as $g) {
        $out .= '<fieldset><legend>' . h($g['legend']) . '</legend><div class="admin-form">'
            . adminField($g['id'] . '-email', 'Email', '<input type="email" id="' . $g['id'] . '-email" name="' . $g['email'] . '" value="' . h((string)$values[$g['email']]) . '" required>')
            . adminField($g['id'] . '-name', 'Name', '<input type="text" id="' . $g['id'] . '-name" name="' . $g['name'] . '" value="' . h((string)$values[$g['name']]) . '" required>')
            . '</div></fieldset>';
    }
    return $out . '<div class="admin-form-actions"><button type="submit" class="btn btn-primary">Save</button></div></form>';
}
```

  In `admin.php`: add `require __DIR__ . '/admin-settings.php';` after the `admin-events.php` require, and delete `handleSettings`, `handleSettingsUpdate`, `renderSettingsPage`. `admin.php` should now contain only requires, `adminRequirePost()` and the router.

- [ ] **Step 5: Feedback shell only** — in `admin-feedback.php`, for each of `renderFeedbackListPage` and `renderFeedbackViewPage`:
  - Replace everything from `?><!DOCTYPE html>` through the flash line (`<?php if ($flash): ?>…<?php endif; ?>`) with `ob_start(); ?>`.
  - Replace the closing `</div>` of `.container` through `</html><?php` with:
    - list page: `<?php adminRenderPage('Feedback', 'feedback', ob_get_clean(), $flash);`
    - view page: `<?php adminRenderPage('Feedback #' . (int)$f['id'], 'feedback', ob_get_clean(), $flash);`
  - In the view page, add `<p><a href="admin.php?action=feedback">← Back to list</a></p>` as the first line after `ob_start(); ?>`.
  - Remove the two inline `style="margin-bottom:1.5rem"` attributes (`.detail-card` already has a bottom margin).
  - The body markup (tables, cards, forms) stays as it is.

- [ ] **Step 6: Source tests** — in `tests/AdminSourceTest.php`, `testBackOfficePagesUseTheAdminTabs`: remove the `'admin.php'` entry; set `'admin-feedback.php' => ["adminRenderPage('Feedback', 'feedback'"]`, `'admin-season-links.php' => ["adminRenderPage('Season links', 'season-links'"]`, add `'admin-settings.php' => ["adminRenderPage('Settings', 'settings'"]`. Add:

```php
    public function testEveryAdminTabUsesTheHubPageShell(): void
    {
        foreach (['admin.php', 'admin-users.php', 'admin-events.php', 'admin-clubs.php', 'admin-season-links.php', 'admin-settings.php', 'admin-feedback.php'] as $file) {
            $src = $this->src($file);
            $this->assertStringNotContainsString('<!DOCTYPE html>', $src, $file);
            $this->assertStringNotContainsString('renderSiteHeader(', $src, $file);
        }
    }
```

- [ ] **Step 7: Run** — `php phpunit.phar` → all green; `grep -n "function render" wcma-calculator/admin.php` → no output.
- [ ] **Step 8: Commit** — `git add -A wcma-calculator && git commit -m "feat(admin): Season links and Settings layouts; every admin tab on the hub page shell"`

---

### Task 7: Audit, screenshots, final check

**Files:**
- Modify: `wcma-calculator/tests/ux/audit.mjs`

- [ ] **Step 1: Screenshots.** Scratch copy with seeded data, admin session forced by a prepend file (never in the repo):

```bash
S="<scratchpad>/admin-shots"; rm -rf "$S"; mkdir -p "$S/site"
cd wcma-calculator && git ls-files | grep -v '^tests/' | while read f; do mkdir -p "$S/site/$(dirname "$f")"; cp "$f" "$S/site/$f"; done
cp config.php "$S/site/"; rm -f "$S/site/data/submissions.db"; (cd "$S/site" && php seed-hub-db.php)
printf '%s\n' '<?php' "define('DB_PATH', '$S/site/data/submissions.db');" 'session_start();' \
  "if (!isset(\$_SESSION['user_id'])) { \$_SESSION['user_id']=1; \$_SESSION['user_name']='Admin'; \$_SESSION['user_role']='admin'; \$_SESSION['user_is_media']=0; }" > "$S/prepend.php"
(cd "$S/site" && php -d auto_prepend_file="$S/prepend.php" -S 127.0.0.1:8765) &   # run in background
E="/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"
for a in users events clubs season-links settings feedback; do for w in 1440 390; do
  "$E" --headless=new --hide-scrollbars --window-size=$w,1500 --screenshot="$S/$a-$w.png" "http://127.0.0.1:8765/admin.php?action=$a"; done; done
"$E" --headless=new --window-size=1440,1000 --screenshot="$S/users-error.png" "http://127.0.0.1:8765/admin.php?action=users&edit=1"
```

  Look at every image. Check: header on one line at 1440; user rows one line tall; no chip floating at the top of a cell; tables are cards at 390 with Edit visible; web-address fields full width; Settings in three groups. Stop the server when done.

- [ ] **Step 2: Audit the admin tabs.** In `tests/ux/audit.mjs`:
  - Remove the two `btn-role` lines from `styleFixturesInPage()`'s `box.innerHTML` and the `if (bg('fx-role') === bg('fx-role2')) …` line.
  - Insert before `} finally {`:

```js
  // Admin tabs (admin desktop UX spec 2026-09-29): phone rules, the edit modal, Deactivate's confirm
  // inside the modal, and one-line user rows on desktop.
  const signInAdmin = async ctx => {
    const p = await ctx.newPage();
    await p.goto(BASE + '/auth.php?action=login');
    await p.fill('#email', process.env.UX_ADMIN_EMAIL || 'matt.sinfield@gmail.com');
    await p.fill('input[name=password]', 'password123');
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    return p;
  };
  const phoneAdmin = await browser.newContext({ viewport: { width: 375, height: 800 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const ap = await signInAdmin(phoneAdmin);
  for (const tab of ['users', 'events', 'clubs', 'season-links', 'settings']) {
    await ap.goto(BASE + '/admin.php?action=' + tab);
    await audit(ap, 'admin ' + tab);
  }
  await ap.goto(BASE + '/admin.php?action=users');
  await ap.click('#users-table [data-dialog-open]');
  await audit(ap, 'admin user modal');
  await ap.click('dialog[open] .admin-dialog-danger button[type=submit]');
  await ap.click('dialog[open] .confirm-modal [data-role=cancel]', { timeout: 5000 });
  const stillOpen = await ap.locator('dialog.admin-dialog[open]').count();
  report('admin: Deactivate asks inside the modal, Cancel keeps the modal open', stillOpen === 1 ? [] : ['the modal closed, or the confirm box could not be answered']);
  await phoneAdmin.close();

  const deskAdmin = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const dp = await signInAdmin(deskAdmin);
  await dp.goto(BASE + '/admin.php?action=users');
  const tallest = await dp.evaluate(() => Math.max(...[...document.querySelectorAll('#users-table tbody tr')].map(r => r.getBoundingClientRect().height)));
  report('admin: user rows at 1280px are one line', tallest <= 110 ? [] : [`a user row is ${Math.round(tallest)}px tall`]);
  await deskAdmin.close();
```

- [ ] **Step 3: Run the audit** — from the repo root `bash wcma-calculator/tests/ux/run-audit.sh` → `All pages pass`. Fix any admin problem it reports in `css/hub.css` (not by loosening the audit), re-run.
- [ ] **Step 4: Full verification** — from `wcma-calculator/`: `php phpunit.phar` (all green, count = baseline − 1 removed + new tests), `node --test tests/js/*.test.js` (baseline + 6), audit passes. `git grep -n "btn-role\|set-role\|set-name\|action=set-media\|event-club\|renderSiteHeader('Users\|renderSiteHeader('Events" -- wcma-calculator ':!wcma-calculator/tests/ux/node_modules'` → no output.
- [ ] **Step 5: Commit** — `git add wcma-calculator/tests/ux/audit.mjs && git commit -m "test(ux): phone audit covers the admin tabs and the edit modal"`
- [ ] **Step 6: Hand off** — use superpowers:finishing-a-development-branch. Pushing `main` deploys (server autopull), so merge/push only when the user says so. No database change, so no reset is needed on the server.
