# WCMA Hub Phase 2: Shell and Home Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the hub its own look and front door:
- one shared page layout and stylesheet (mockup B's identity)
- a public landing page, plus a signed-in **Home** built around a numbered to-do list for the events a competitor has tagged
- event tagging, and the "I'll do it at the track" choice
- the calculator moved to `calculator.php`: bound to a car, pre-filled from that car's last declaration, and able to keep an anonymous visitor's entries across sign-in
- a Profile page.

**Architecture:**
- Plain PHP + SQLite, as in Phase 1. `layout.php` renders the head, header, navigation and footer for every page. `css/hub.css` holds the design tokens and shared components; it loads after `css/calculator.css` so old pages pick up the new look without being rebuilt. (Garage/Drivers are rebuilt in Phase 3, Inspector in Phase 4.)
- The readiness logic is a pure function in `readiness-lib.php`. A thin DB loader feeds it, and a pure render function in `home-page.php` turns its output into HTML. Both pure pieces are unit tested.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit 10 (`php phpunit.phar`), vanilla JS, `node --test` for JS unit tests.

**Spec:** `docs/superpowers/specs/2026-09-24-wcma-hub-design.md`. Read §1 (IA), §3 (Home and readiness), §4 (Class Calculator) and §6 (visual system and page layer). The chosen mockups are `scratch/hub-mockups/c-todo-desktop.png` (layout) and `b-garage-desktop.png` (visual identity), with HTML sources alongside.

## Global Constraints

- All paths below are relative to `wcma-calculator/` unless they start with `docs/`. Run PHPUnit from `wcma-calculator/`: `php phpunit.phar`. Run the JS tests with `node --test tests/js/*.test.js`; the bare directory form fails to resolve on Windows.
- No new dependencies and no build step. Fonts load from Google Fonts: Archivo 400/500/600/700/800 and Archivo Narrow 600/700.
- **Terminology:** use *reviewed*, *accepted* and *pre-teched*. Never use *approved*, *passed* or *safe* in UI or email copy.
- **Not blocking:** a tech sheet can always be submitted, whatever the car, gear or declaration status.
- **Calculator:** real-time recalculation must not change. In `js/ui-controller.js`, only the load/restore code named in Task 8 may change. The calculation wiring and `js/calculator.js` are untouched.
- **Accessibility floor:**
  - 18px base text and a 44px minimum tap target.
  - Visible focus rings.
  - Status always shown as a word, never colour alone.
  - Navigation labels are always text; on phones they collapse into a labelled "Menu" button.
- **Escape everything** from the DB or the request with `h()` (`view_helpers.php`), and URLs in `href` too.
- Tagging an event **does not register** anyone. Wherever tagging appears, the copy says: "This doesn't register you. Register with the host club."
- The app isn't live, so schema changes need no migration. **After Task 3, reset your local DB:** `php reset-hub-db.php --confirm && php seed-hub-db.php`.
- Nothing is pushed to GitHub. Work on a branch (e.g. `hub-phase2`) and commit at the end of every task. Every commit message ends with:
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

---

## File map

| File | Status | Responsibility |
|---|---|---|
| `css/hub.css` | create | Tokens and shared components (nav, buttons, cards, plate, class badge, status, to-do list, footer) |
| `layout.php` | create | `renderPageStart()`, `renderPageEnd()`, `hubNavHtml()`, `hubFooterHtml()` |
| `js/nav.js` | create | Phone "Menu" toggle |
| `view_helpers.php` | modify | `renderSiteHeader()` delegates to the layout header; `renderCommonNav()` is removed |
| every page that calls `renderSiteHeader()`, plus `auth.php` | modify | New header signature; load `hub.css` |
| `db.php` | modify | Event plans, at-track choices, gear-by-driver lookup, `submissions.form_data`, password update |
| `events-lib.php` | create | Tagging rules (`eventsTagCar`, `eventsUntagCar`) |
| `readiness-lib.php` | create | `buildReadiness()` (pure) and `loadReadinessInputs()` (DB) |
| `home-page.php` | create | `renderLandingHtml()`, `renderHomeHtml()` (pure HTML builders) |
| `index.php` | rewrite | Landing (signed out), Home (signed in), tag / untag / at-track actions |
| `calculator.php` | create | The calculator inside the layout, with account- and car-bound fields |
| `car-classing.html` | rewrite | Redirect to `calculator.php`, keeping the query string |
| `js/declaration-state.js` | create | Pure helpers: stash/restore the form for sign-in, map a declaration to form data |
| `js/ui-controller.js` | modify | `applyFormData()` extracted from `loadConfiguration()`; load on `?car=` and `?restore=1` |
| `js/form-handler.js` | modify | On a 401, stash the form and send the visitor to sign in |
| `cars.php` | modify | `action=declaration` returns a car's current declaration form data |
| `car-classing.php` | modify | Stores the posted `form_data` JSON on the declaration |
| `gear.php` | modify | `action=start`: create this season's gear record for a driver, then open its photos |
| `tech-sheets.php` | modify | Pre-selects `?event_id=`; submitting a sheet tags the event |
| `profile.php` | create | Name and password |
| `auth.php` | modify | Redirect whitelist and links point at the new pages |

---

### Task 1: Hub stylesheet and page layout

**Files:**
- Create: `css/hub.css`, `layout.php`, `js/nav.js`, `tests/LayoutTest.php`

**Interfaces:**
- Produces (`layout.php`):
  - `hubNavItems(?array $user): array`: an ordered list of `['key','label','href']`. Signed-out users get `home`, `calculator`, `signin`. Signed-in users get `home`, `garage` (→ `account.php` until Phase 3), `drivers` (→ `gear.php` until Phase 3) and `calculator` (→ `calculator.php`). Staff also get `staff` → `admin.php`, labelled `Inspector` for the inspector role and `Admin` for admin.
  - `hubNavHtml(?array $user, string $section): string`: the `<nav class="hub-nav">`. The item whose key equals `$section` renders as `<span class="hub-nav-current" aria-current="page">`; every other item is an `<a>`. There's a divider before `staff`.
  - `hubAccountHtml(?array $user): string`: signed in, `<name> · <a href="profile.php">Profile</a> · <a href="auth.php?action=logout">Sign out</a>`; signed out, an empty string.
  - `hubFooterHtml(): string`: links to `#` (`data-feedback-open`, "Feedback"), `https://www.wcma.ca/racing/racing-regulations/` ("Sporting &amp; Technical Regulations") and `https://www.wcma.ca` ("wcma.ca").
  - `renderPageStart(string $title, string $section, array $opts = []): void`: echoes the doctype, `<head>` and `<body>`, header, stripe and flash. `$opts` keys:
    - `extraHead`: raw HTML appended to `<head>`
    - `subnav`: raw HTML, rendered as `<div class="hub-subnav">` under the header
    - `flash`: a `?array` from `getFlash()`
    - `bodyClass`: string.
  - `renderPageEnd(array $opts = []): void`: echoes the footer, the `js/nav.js` and `js/feedback.js` scripts, then `$opts['scripts']` (raw HTML), then `</body></html>`.
  - `<title>` is `"{$title} — WCMA Hub"`.

- [ ] **Step 1: Write the failing test**

`tests/LayoutTest.php`:

```php
<?php
// wcma-calculator/tests/LayoutTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../layout.php';

use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    private function keys(?array $user): array {
        return array_column(hubNavItems($user), 'key');
    }

    public function testNavItemsByAudience(): void
    {
        $this->assertSame(['home', 'calculator', 'signin'], $this->keys(null));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator'], $this->keys(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user']));
        $this->assertSame(['home', 'garage', 'drivers', 'calculator', 'staff'], $this->keys(['id' => 1, 'name' => 'Ivy Inspector', 'role' => 'inspector']));

        $staff = array_column(hubNavItems(['id' => 1, 'name' => 'Ivy Inspector', 'role' => 'inspector']), 'label', 'key');
        $this->assertSame('Inspector', $staff['staff']);
        $admin = array_column(hubNavItems(['id' => 1, 'name' => 'Site Admin', 'role' => 'admin']), 'label', 'key');
        $this->assertSame('Admin', $admin['staff']);
        $this->assertSame('Class Calculator', $admin['calculator']);
    }

    public function testCurrentSectionIsInertText(): void
    {
        $html = hubNavHtml(['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user'], 'home');
        $this->assertStringContainsString('<span class="hub-nav-current" aria-current="page">Home</span>', $html);
        $this->assertStringNotContainsString('href="index.php"', $html);
        $this->assertStringContainsString('href="account.php"', $html);
        $this->assertStringContainsString('href="calculator.php"', $html);
        $this->assertStringContainsString('>Menu<', $html);                     // phone toggle is a labelled button
    }

    public function testAccountAndFooterAreEscapedAndComplete(): void
    {
        $html = hubAccountHtml(['id' => 1, 'name' => 'A <b>', 'role' => 'user']);
        $this->assertStringContainsString('A &lt;b&gt;', $html);
        $this->assertStringContainsString('href="profile.php"', $html);
        $this->assertStringContainsString('action=logout', $html);
        $this->assertSame('', hubAccountHtml(null));

        $footer = hubFooterHtml();
        foreach (['data-feedback-open', 'racing-regulations', 'https://www.wcma.ca'] as $needle) {
            $this->assertStringContainsString($needle, $footer);
        }
    }

    public function testPageShellLoadsBothStylesheetsInOrder(): void
    {
        ob_start();
        renderPageStart('Home', 'home', ['flash' => ['message' => 'Saved <ok>', 'type' => 'success']]);
        renderPageEnd();
        $html = ob_get_clean();

        $this->assertStringContainsString('<title>Home — WCMA Hub</title>', $html);
        $this->assertLessThan(strpos($html, 'css/hub.css'), strpos($html, 'css/calculator.css'));
        $this->assertStringContainsString('Saved &lt;ok&gt;', $html);
        $this->assertStringContainsString('js/nav.js', $html);
        $this->assertStringContainsString('class="hub-stripe"', $html);
        $this->assertStringContainsString('fonts.googleapis.com', $html);
    }
}
```

`layout.php` calls `current_user()`, so the test needs it defined. `tests/bootstrap.php` doesn't load `session_bootstrap.php`, because that starts a session. Add this to `tests/bootstrap.php`:

```php
if (!function_exists('current_user')) {
    function current_user(): ?array { return $GLOBALS['TEST_CURRENT_USER'] ?? null; }
}
require_once __DIR__ . '/../roles.php';
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter LayoutTest`
Expected: FAIL (`layout.php` missing).

- [ ] **Step 3: Implement `layout.php`**

```php
<?php
// wcma-calculator/layout.php
//
// The hub page shell: head, header (logo, primary nav, account links), red/black stripe,
// optional sub-navigation, flash message and footer. Callers must have loaded view_helpers.php
// (h()) and roles.php (user_has_role()); current_user() comes from session_bootstrap.php.

function hubNavItems(?array $user): array {
    if ($user === null) {
        return [
            ['key' => 'home', 'label' => 'Home', 'href' => 'index.php'],
            ['key' => 'calculator', 'label' => 'Class Calculator', 'href' => 'calculator.php'],
            ['key' => 'signin', 'label' => 'Sign in', 'href' => 'auth.php?action=login'],
        ];
    }
    $items = [
        ['key' => 'home', 'label' => 'Home', 'href' => 'index.php'],
        ['key' => 'garage', 'label' => 'Garage', 'href' => 'account.php'],     // garage.php in Phase 3
        ['key' => 'drivers', 'label' => 'Drivers', 'href' => 'gear.php'],      // drivers.php in Phase 3
        ['key' => 'calculator', 'label' => 'Class Calculator', 'href' => 'calculator.php'],
    ];
    if (user_has_role($user, 'inspector')) {
        $items[] = ['key' => 'staff', 'label' => user_has_role($user, 'admin') ? 'Admin' : 'Inspector', 'href' => 'admin.php'];
    }
    return $items;
}

function hubNavHtml(?array $user, string $section): string {
    $out = '<button type="button" class="hub-menu-btn" aria-expanded="false" aria-controls="hub-nav-list">Menu</button>';
    $out .= '<nav class="hub-nav" aria-label="Main"><ul id="hub-nav-list">';
    foreach (hubNavItems($user) as $item) {
        $cls = $item['key'] === 'staff' ? ' class="hub-nav-staff"' : '';
        $inner = $item['key'] === $section
            ? '<span class="hub-nav-current" aria-current="page">' . h($item['label']) . '</span>'
            : '<a href="' . h($item['href']) . '">' . h($item['label']) . '</a>';
        $out .= '<li' . $cls . '>' . $inner . '</li>';
    }
    return $out . '</ul></nav>';
}

function hubAccountHtml(?array $user): string {
    if ($user === null) return '';
    return '<div class="hub-account"><span class="hub-account-name">' . h((string)$user['name']) . '</span>'
        . ' · <a href="profile.php">Profile</a> · <a href="auth.php?action=logout">Sign out</a></div>';
}

function hubFooterHtml(): string {
    return '<footer class="hub-footer"><div class="hub-wrap">'
        . '<a href="#" data-feedback-open>Feedback</a>'
        . '<a href="https://www.wcma.ca/racing/racing-regulations/" target="_blank" rel="noopener">Sporting &amp; Technical Regulations</a>'
        . '<a href="https://www.wcma.ca" target="_blank" rel="noopener">wcma.ca</a>'
        . '</div></footer>';
}

function renderPageStart(string $title, string $section, array $opts = []): void {
    $user = current_user();
    $flash = $opts['flash'] ?? null;
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — WCMA Hub</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Narrow:wght@600;700&display=swap">
<link rel="stylesheet" href="css/calculator.css">
<link rel="stylesheet" href="css/hub.css">
<?= $opts['extraHead'] ?? '' ?>
</head>
<body class="hub <?= h((string)($opts['bodyClass'] ?? '')) ?>">
<a class="hub-skip" href="#main">Skip to content</a>
<header class="hub-header"><div class="hub-wrap hub-header-row">
  <a href="index.php" class="hub-logo"><img src="assets/wcma-logo.png" alt="WCMA — home"></a>
  <?= hubNavHtml($user, $section) ?>
  <?= hubAccountHtml($user) ?>
</div></header>
<div class="hub-stripe" aria-hidden="true"></div>
<?php if (!empty($opts['subnav'])): ?><div class="hub-subnav"><div class="hub-wrap"><?= $opts['subnav'] ?></div></div><?php endif; ?>
<main id="main" class="hub-wrap hub-main">
<?php if ($flash): ?><div class="form-messages show <?= h((string)$flash['type']) ?>" role="status"><?= h((string)$flash['message']) ?></div><?php endif; ?>
<?php
}

function renderPageEnd(array $opts = []): void {
    ?>
</main>
<?= hubFooterHtml() ?>
<script src="js/nav.js" defer></script>
<script src="js/feedback.js" defer></script>
<?= $opts['scripts'] ?? '' ?>
</body>
</html><?php
}
```

- [ ] **Step 4: Implement `js/nav.js`**

```js
// wcma-calculator/js/nav.js — phone "Menu" button for the hub header.
(function () {
    const btn = document.querySelector('.hub-menu-btn');
    const list = document.getElementById('hub-nav-list');
    if (!btn || !list) return;
    btn.addEventListener('click', () => {
        const open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        list.classList.toggle('is-open', !open);
    });
})();
```

- [ ] **Step 5: Implement `css/hub.css`**

Write the stylesheet with these exact tokens and components. The values come from mockup B (`scratch/hub-mockups/b-garage.html`); copy its component styling where a class below matches.

```css
/* wcma-calculator/css/hub.css — WCMA Hub design system. Loaded after calculator.css. */
:root {
  --hub-red: #d2151e; --hub-red-ink: #a80f16; --hub-ink: #111317; --hub-ink-2: #555b64;
  --hub-line: #e4e6ea; --hub-paper: #f2f3f5; --hub-card: #ffffff;
  --hub-ok: #2fae5f; --hub-ok-ink: #17703a; --hub-ok-bg: #e9f6ee;
  --hub-warn: #f0a400; --hub-warn-ink: #7d5500; --hub-warn-bg: #fdf3dc;
  --hub-todo: #e2323a; --hub-todo-bg: #fdecec;
  --hub-radius: 10px; --hub-tap: 44px;
  --hub-font: 'Archivo', system-ui, -apple-system, 'Segoe UI', sans-serif;
  --hub-font-narrow: 'Archivo Narrow', 'Archivo', system-ui, sans-serif;
}
body.hub { font-family: var(--hub-font); font-size: 18px; line-height: 1.5; color: var(--hub-ink); background: var(--hub-paper); margin: 0; }
body.hub .container { max-width: 1180px; }
.hub-wrap { max-width: 1180px; margin: 0 auto; padding: 0 24px; }
.hub-main { padding-top: 24px; padding-bottom: 48px; }
.hub-skip { position: absolute; left: -9999px; }
.hub-skip:focus { left: 16px; top: 8px; background: #fff; padding: 8px 12px; z-index: 10; }
body.hub :focus-visible { outline: 3px solid var(--hub-red); outline-offset: 2px; }
body.hub h1, body.hub h2 { font-family: var(--hub-font-narrow); line-height: 1.15; }
```

Then add rules for each of these classes, following mockup B:

- **Header:** `.hub-header` (white), `.hub-header-row` (flex, center, gap 24px, padding 12px 24px), `.hub-logo img` (height 38px).
- **Navigation:**
  - `.hub-nav ul` (flex, gap 4px, list-style none, margin 0, padding 0)
  - `.hub-nav a`, `.hub-nav-current` (min-height var(--hub-tap), inline-flex, align center, padding 0 14px, font-weight 600, colour ink, no underline, 3px transparent bottom border)
  - `.hub-nav-current` (bottom border red)
  - `.hub-nav-staff` (left border line, margin-left 8px, padding-left 8px, links ink-2).
- **Account and menu:** `.hub-account` (margin-left auto, font-size 16px), `.hub-menu-btn` (hidden on desktop).
- **Stripe:** `.hub-stripe` (height 6px; `background: repeating-linear-gradient(90deg, var(--hub-red) 0 60px, var(--hub-ink) 60px 64px)`).
- **Sub-navigation:** `.hub-subnav` (white, bottom border line, font-size 16px, padding 8px 0; links red-ink).
- **Footer:** `.hub-footer` (margin-top 48px, padding 24px 0, top border line; links ink-2 with a 24px gap between them).
- **Buttons:**
  - `.hub-btn` (min-height var(--hub-tap), inline-flex, center, padding 0 18px, border-radius 8px, background red, white text, font-weight 700, font-size 17px, no underline, no border, cursor pointer)
  - `.hub-btn--secondary` (white background, ink text, 2px ink border)
  - `.hub-btn--link` (transparent, red-ink text, underline, padding 0 4px).
- **Cards:** `.hub-card` (white background, radius, `box-shadow 0 1px 2px rgba(0,0,0,.06)`, padding 18px 20px, margin-bottom 18px).
- **Plates and badges:**
  - `.hub-plate` (round number plate: 48px circle, 4px ink border, Archivo Narrow 700, font-size 22px, grid centred)
  - `.hub-plate--lg` (84px, font-size 40px, 5px border)
  - `.hub-class` (ink background, white text, Archivo Narrow 700, font-size 15px, padding 2px 10px, radius 4px).
- **Status:**
  - `.hub-status` (inline-flex, gap 6px, font-weight 600, font-size 16px)
  - `.hub-status::before` (11px dot, border-radius 50%)
  - modifiers `--ok` / `--warn` / `--todo` / `--info` set the dot colour (ok / warn / todo / ink-2) and the text colour (ok-ink / warn-ink / todo / ink-2).
- **To-do list:**
  - `.hub-todo` (white background, 2px ink border, radius)
  - `.hub-todo-item` (flex, gap 18px, align center, padding 20px 22px, top border line; the first item has no border)
  - `.hub-todo-n` (38px red circle, white bold number)
  - `.hub-todo-item--optional .hub-todo-n` (paper background, ink-2, 2px line border)
  - `.hub-todo-txt strong` (display block, 20px); `.hub-todo-txt span` (ink-2, 17px)
  - `.hub-todo-actions` (margin-left auto, flex, gap 10px, align center).
- **Home sections:**
  - `.hub-done` (a `<details>` card; its summary is bold ok-ink)
  - `.hub-grid-2` (a two-column grid, 18px gap)
  - `.hub-line` (flex space-between, 8px vertical padding, top border line).
- **Landing:** `.hub-hero` (dark ink background, white text, padding 28px 0; `h1` is Archivo Narrow, 36px).
- **Phone** (`@media (max-width: 700px)`):
  - `.hub-menu-btn` shows (min-height var(--hub-tap), 2px ink border, radius 8px, white, bold, margin-left auto).
  - `#hub-nav-list` is hidden until `.is-open`, then shown as a vertical list (full width, wrapped below the header row).
  - `.hub-account` becomes full width.
  - `.hub-todo-item` wraps, and `.hub-todo-actions` takes full width with `.hub-btn` stretched.
  - `.hub-grid-2` becomes one column.
  - `.hub-wrap` padding drops to 0 16px.

Contrast check: every text colour above on its background meets WCAG AA (ok-ink `#17703a` and warn-ink `#7d5500` on white both pass). Keep those pairs.

- [ ] **Step 6: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add css/hub.css layout.php js/nav.js tests/LayoutTest.php tests/bootstrap.php
git commit -m "feat(hub): shared page layout, navigation and hub stylesheet"
```

---

### Task 2: Every existing page uses the hub header

**Files:**
- Modify: `view_helpers.php` (`renderSiteHeader()`; remove `renderCommonNav()` and `navItem()` only if nothing else uses them)
- Modify every caller of `renderSiteHeader()`: `account.php`, `gear-page.php`, `pretech-page.php`, `tech-sheets.php`, `admin.php`, `admin-tech-sheets.php`, `admin-gear.php`, `admin-feedback.php`, `admin-season-links.php` (find them all with `grep -rn "renderSiteHeader(" --include=*.php . | grep -v tests`)
- Modify: `auth.php` (`renderAuthPage()`), `tests/AdminNavTest.php`
- Create: `tests/HeaderAdoptionTest.php`

**Interfaces:**
- Consumes: `hubNavHtml()`, `hubAccountHtml()` (Task 1)
- Produces: `renderSiteHeader(string $title, string $subnavHtml = '', string $section = ''): void`. It echoes the hub header, the stripe, an optional `.hub-subnav` holding `$subnavHtml`, then `<h1 class="hub-page-title">`. The `<head>` of every existing page links `css/hub.css` after `css/calculator.css`, adds the font link, and has `class="hub"` on `<body>`.

- [ ] **Step 1: Write the failing test**

`tests/HeaderAdoptionTest.php`:

```php
<?php
// wcma-calculator/tests/HeaderAdoptionTest.php — every page shares the hub header and stylesheet.
use PHPUnit\Framework\TestCase;

final class HeaderAdoptionTest extends TestCase
{
    private function pageFiles(): array {
        $files = [];
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            if (preg_match('/<link rel="stylesheet" href="css\/calculator\.css">/', file_get_contents($f))) $files[] = $f;
        }
        return $files;
    }

    public function testEveryPageThatLoadsCalculatorCssAlsoLoadsHubCssAfterIt(): void
    {
        $this->assertNotEmpty($this->pageFiles());
        foreach ($this->pageFiles() as $f) {
            $src = file_get_contents($f);
            $this->assertMatchesRegularExpression('/css\/calculator\.css">\s*\n?\s*<link rel="stylesheet" href="css\/hub\.css">/', $src, basename($f));
        }
    }

    public function testNoPageStillUsesTheOldCommonNav(): void
    {
        foreach (glob(__DIR__ . '/../*.php') as $f) {
            $this->assertStringNotContainsString('renderCommonNav(', file_get_contents($f), basename($f));
        }
    }

    public function testSiteHeaderRendersHubHeaderSubnavAndTitle(): void
    {
        require_once __DIR__ . '/../view_helpers.php';
        require_once __DIR__ . '/../layout.php';
        $GLOBALS['TEST_CURRENT_USER'] = ['id' => 1, 'name' => 'Jordan Lee', 'role' => 'user'];
        ob_start();
        renderSiteHeader('My Cars', '<a href="account.php">← Back</a>', 'garage');
        $html = ob_get_clean();
        unset($GLOBALS['TEST_CURRENT_USER']);

        $this->assertStringContainsString('class="hub-header"', $html);
        $this->assertStringContainsString('<span class="hub-nav-current" aria-current="page">Garage</span>', $html);
        $this->assertStringContainsString('class="hub-subnav"', $html);
        $this->assertStringContainsString('← Back', $html);
        $this->assertStringContainsString('<h1 class="hub-page-title">My Cars</h1>', $html);
    }
}
```

In `tests/AdminNavTest.php`, keep the `renderAdminNav()` tests unchanged; `renderAdminNav()` stays. Only delete tests that call `renderCommonNav()`, if there are any.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter HeaderAdoptionTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

`view_helpers.php`: add `require_once __DIR__ . '/layout.php';` at the top, then replace `renderSiteHeader()`:

```php
/**
 * Header for pages not yet rebuilt on renderPageStart(): the hub header and stripe, an optional
 * sub-navigation bar (back links, admin tabs), then the page title.
 */
function renderSiteHeader(string $title, string $subnavHtml = '', string $section = ''): void {
    $user = current_user();
    ?>
<header class="hub-header"><div class="hub-wrap hub-header-row">
  <a href="index.php" class="hub-logo"><img src="assets/wcma-logo.png" alt="WCMA — home"></a>
  <?= hubNavHtml($user, $section) ?>
  <?= hubAccountHtml($user) ?>
</div></header>
<div class="hub-stripe" aria-hidden="true"></div>
<?php if ($subnavHtml !== ''): ?><div class="hub-subnav"><?= $subnavHtml ?></div><?php endif; ?>
<h1 class="hub-page-title"><?= h($title) ?></h1>
<script src="js/nav.js" defer></script>
<script src="js/feedback.js" defer></script>
<?php
}
```

Delete `renderCommonNav()`. Keep `navItem()`, because `renderAdminNav()` uses it.

Rewrite every caller mechanically. `renderSiteHeader(T, X . renderCommonNav('S'))` becomes `renderSiteHeader(T, X, SECTION)`, and `renderSiteHeader(T, renderCommonNav('S'))` becomes `renderSiteHeader(T, '', SECTION)`, where SECTION maps:

| old `renderCommonNav` arg | new section |
|---|---|
| `account` | `garage` |
| `gear` | `drivers` |
| `admin` | `staff` |
| `calculator` | `calculator` |
| `auth` | `''` |

In each of those files' `<head>`:
- insert `<link rel="stylesheet" href="css/hub.css">` on the line directly after `<link rel="stylesheet" href="css/calculator.css">`;
- add the Google Fonts `<link>` from `renderPageStart()` before it;
- change `<body>` to `<body class="hub">`.

`auth.php` `renderAuthPage()`: rebuild it on the layout. Replace its whole body with:

```php
function renderAuthPage(string $title, string $bodyHtml): void {
    renderPageStart($title, 'signin');
    echo '<div class="hub-card auth-box"><h1>' . h($title) . '</h1>' . $bodyHtml . '</div>';
    renderPageEnd(['scripts' => '<script src="js/auth.js" defer></script>']);
}
```

`auth.php` must `require __DIR__ . '/layout.php';` if `view_helpers.php` doesn't already pull it in (it will after this task).

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS. Run `php -l` on every modified file.

- [ ] **Step 5: Commit**

```bash
git add -A *.php tests/
git commit -m "feat(hub): every page shares the hub header, navigation and stylesheet"
```

---

### Task 3: Event plans, at-track choices and tagging

**Files:**
- Modify: `db.php` (the `at_track_choices` table; event-plan, at-track and gear-by-driver functions)
- Create: `events-lib.php`, `tests/EventsLibTest.php`
- Modify: `tech-sheets.php` (`handleSubmit()` tags the event; `handleNew()` pre-selects `?event_id=`)

**Interfaces:**
- Produces (`db.php`):
  - `db_tag_event(PDO $pdo, int $userId, int $eventId, int $carId): void`: idempotent (`INSERT OR IGNORE`).
  - `db_untag_event(PDO $pdo, int $userId, int $eventId, int $carId): void`
  - `db_get_user_event_plans(PDO $pdo, int $userId): array`: rows `{event_id, car_id}`.
  - `db_set_at_track(PDO $pdo, string $subjectType, int $subjectId, int $season): void`: idempotent. `$subjectType` is `'car'` or `'driver'`.
  - `db_get_at_track_keys(PDO $pdo, array $carIds, array $driverIds, int $season): array`: a list of strings like `"car:3"` / `"driver:7"`.
  - `db_get_gear_record_for_driver(PDO $pdo, int $driverId, int $season): ?array`: uses `DB_GEAR_SELECT`.
- Produces (`events-lib.php`):
  - `eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId): array{ok: bool, error: ?string}`. It checks the car belongs to the user and isn't archived, and that the event exists and is active.
  - `eventsUntagCar(PDO $pdo, int $userId, int $eventId, int $carId): array{ok: bool, error: ?string}`
  - `eventsSetAtTrack(PDO $pdo, int $userId, string $subjectType, int $subjectId, int $season): array{ok: bool, error: ?string}`. It checks the user owns the car or driver.

- [ ] **Step 1: Write the failing test**

`tests/EventsLibTest.php`:

```php
<?php
// wcma-calculator/tests/EventsLibTest.php
require_once __DIR__ . '/../events-lib.php';

use PHPUnit\Framework\TestCase;

final class EventsLibTest extends TestCase
{
    private function setUpWorld(PDO $pdo): array {
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o@example.com', 'name' => 'Other Person', 'password_hash' => 'x', 'google_id' => null]);
        $car = db_create_car($pdo, $u, ['car_number' => '42', 'make' => 'Honda', 'model' => 'S2000']);
        $theirs = db_create_car($pdo, $other, ['car_number' => '7', 'make' => 'Mazda', 'model' => 'MX-5']);
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        return [$u, $other, $car, $theirs, $event];
    }

    public function testTagIsIdempotentAndUntagRemoves(): void
    {
        $pdo = make_temp_pdo();
        [$u, , $car, , $event] = $this->setUpWorld($pdo);

        $this->assertTrue(eventsTagCar($pdo, $u, $event, $car)['ok']);
        $this->assertTrue(eventsTagCar($pdo, $u, $event, $car)['ok']);
        $this->assertSame([['event_id' => $event, 'car_id' => $car]], array_map(
            fn($r) => ['event_id' => (int)$r['event_id'], 'car_id' => (int)$r['car_id']], db_get_user_event_plans($pdo, $u)));

        $this->assertTrue(eventsUntagCar($pdo, $u, $event, $car)['ok']);
        $this->assertSame([], db_get_user_event_plans($pdo, $u));
    }

    public function testCannotTagSomeoneElsesCarAnArchivedCarOrAnInactiveEvent(): void
    {
        $pdo = make_temp_pdo();
        [$u, , $car, $theirs, $event] = $this->setUpWorld($pdo);
        $this->assertFalse(eventsTagCar($pdo, $u, $event, $theirs)['ok']);

        db_set_event_active($pdo, $event, false);
        $this->assertFalse(eventsTagCar($pdo, $u, $event, $car)['ok']);

        db_set_event_active($pdo, $event, true);
        db_archive_car($pdo, $u, $car);
        $this->assertFalse(eventsTagCar($pdo, $u, $event, $car)['ok']);
    }

    public function testAtTrackChoiceIsOwnedAndIdempotent(): void
    {
        $pdo = make_temp_pdo();
        [$u, $other, $car, $theirs] = $this->setUpWorld($pdo);
        $self = (int)db_get_self_driver($pdo, $u)['id'];

        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026)['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'car', $car, 2026)['ok']);
        $this->assertTrue(eventsSetAtTrack($pdo, $u, 'driver', $self, 2026)['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'car', $theirs, 2026)['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'driver', (int)db_get_self_driver($pdo, $other)['id'], 2026)['ok']);
        $this->assertFalse(eventsSetAtTrack($pdo, $u, 'boat', $car, 2026)['ok']);

        $this->assertEqualsCanonicalizing(["car:$car", "driver:$self"], db_get_at_track_keys($pdo, [$car], [$self], 2026));
        $this->assertSame([], db_get_at_track_keys($pdo, [$car], [$self], 2027));
    }

    public function testGearRecordForDriver(): void
    {
        $pdo = make_temp_pdo();
        [$u] = $this->setUpWorld($pdo);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        $this->assertNull(db_get_gear_record_for_driver($pdo, $self, 2026));
        gearCreate($pdo, $u, 'Jordan Lee', '', 2026);
        $this->assertSame('Jordan Lee', db_get_gear_record_for_driver($pdo, $self, 2026)['driver_name']);
    }
}
```

The test uses `gearCreate()`, so add `require_once __DIR__ . '/../gear-lib.php';` at the top as well.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter EventsLibTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

`db.php`, in `db_init()` after the `event_plans` table:

```php
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS at_track_choices (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_type TEXT NOT NULL,
            subject_id   INTEGER NOT NULL,
            season       INTEGER NOT NULL,
            created_at   DATETIME NOT NULL,
            UNIQUE (subject_type, subject_id, season)
        )
    ");
```

Add to `db.php`:

```php
// ── Event plans and at-track choices ─────────────────────────────────────────

function db_tag_event(PDO $pdo, int $userId, int $eventId, int $carId): void {
    $pdo->prepare("INSERT OR IGNORE INTO event_plans (user_id, event_id, car_id, created_at) VALUES (:u, :e, :c, :now)")
        ->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId, ':now' => date('Y-m-d H:i:s')]);
}

function db_untag_event(PDO $pdo, int $userId, int $eventId, int $carId): void {
    $pdo->prepare("DELETE FROM event_plans WHERE user_id = :u AND event_id = :e AND car_id = :c")
        ->execute([':u' => $userId, ':e' => $eventId, ':c' => $carId]);
}

function db_get_user_event_plans(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT event_id, car_id FROM event_plans WHERE user_id = :u ORDER BY event_id ASC, car_id ASC");
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

function db_set_at_track(PDO $pdo, string $subjectType, int $subjectId, int $season): void {
    $pdo->prepare("INSERT OR IGNORE INTO at_track_choices (subject_type, subject_id, season, created_at) VALUES (:t, :s, :y, :now)")
        ->execute([':t' => $subjectType, ':s' => $subjectId, ':y' => $season, ':now' => date('Y-m-d H:i:s')]);
}

/** "car:ID" / "driver:ID" keys for the given subjects that chose "I'll do it at the track" this season. */
function db_get_at_track_keys(PDO $pdo, array $carIds, array $driverIds, int $season): array {
    $keys = [];
    $stmt = $pdo->prepare("SELECT subject_type, subject_id FROM at_track_choices WHERE season = :y");
    $stmt->execute([':y' => $season]);
    $cars = array_flip(array_map('intval', $carIds));
    $drivers = array_flip(array_map('intval', $driverIds));
    foreach ($stmt->fetchAll() as $r) {
        $id = (int)$r['subject_id'];
        if (($r['subject_type'] === 'car' && isset($cars[$id])) || ($r['subject_type'] === 'driver' && isset($drivers[$id]))) {
            $keys[] = $r['subject_type'] . ':' . $id;
        }
    }
    return $keys;
}

function db_get_gear_record_for_driver(PDO $pdo, int $driverId, int $season): ?array {
    $stmt = $pdo->prepare(DB_GEAR_SELECT . " WHERE g.driver_id = :d AND g.season = :s");
    $stmt->execute([':d' => $driverId, ':s' => $season]);
    return $stmt->fetch() ?: null;
}
```

`events-lib.php`:

```php
<?php
// wcma-calculator/events-lib.php
//
// Competitors tag the events they are going to, per car. Tagging only drives their checklist and
// reminders: it does not register them with the host club. Callers must have loaded db.php.

const EVENTS_NOT_REGISTERING = 'This doesn\'t register you. Register with the host club.';

/** @return array{ok: bool, error: ?string} */
function eventsTagCar(PDO $pdo, int $userId, int $eventId, int $carId): array {
    $car = db_get_user_car($pdo, $userId, $carId);
    if ($car === null || $car['archived_at'] !== null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    $event = db_get_event($pdo, $eventId);
    if ($event === null || (int)$event['active'] !== 1) return ['ok' => false, 'error' => 'That event is not open.'];
    db_tag_event($pdo, $userId, $eventId, $carId);
    return ['ok' => true, 'error' => null];
}

/** @return array{ok: bool, error: ?string} */
function eventsUntagCar(PDO $pdo, int $userId, int $eventId, int $carId): array {
    if (db_get_user_car($pdo, $userId, $carId) === null) return ['ok' => false, 'error' => 'Choose one of your cars.'];
    db_untag_event($pdo, $userId, $eventId, $carId);
    return ['ok' => true, 'error' => null];
}

/** "I'll do it at the track": planning only, it never accepts anything. @return array{ok: bool, error: ?string} */
function eventsSetAtTrack(PDO $pdo, int $userId, string $subjectType, int $subjectId, int $season): array {
    if ($subjectType === 'car') {
        $owned = db_get_user_car($pdo, $userId, $subjectId) !== null;
    } elseif ($subjectType === 'driver') {
        $driver = db_get_driver($pdo, $subjectId);
        $owned = $driver !== null && (int)$driver['owner_user_id'] === $userId;
    } else {
        return ['ok' => false, 'error' => 'Unknown item.'];
    }
    if (!$owned) return ['ok' => false, 'error' => 'Unknown item.'];
    db_set_at_track($pdo, $subjectType, $subjectId, $season);
    return ['ok' => true, 'error' => null];
}
```

`tech-sheets.php`:
- Add `require __DIR__ . '/events-lib.php';`.
- In `handleSubmit()`, directly after `carsApplySheetDetails(...)`, add `db_tag_event($pdo, (int)$user['id'], $eventId, $carId);`. Submitting a sheet for an event tags it.
- In the `new` router case, pass `(int)($_GET['event_id'] ?? 0)` as a new fourth argument, making the signature `handleNew(PDO $pdo, array $user, int $carId, int $eventId = 0)`.
- Forward that to `renderTechSheetForm(...)` as a new trailing `int $preselectEventId = 0`. In the form, set `$selectedEventId = $isEdit ? (int)$existingSheet['event_id'] : ($preselectEventId ?: null);`.

Add to `tests/TechSheetsHandlersTest.php`:

```php
    public function testSubmittingASheetTagsTheEventAndNewCanPreselectIt(): void
    {
        $this->assertStringContainsString('db_tag_event(', $this->body('handleSubmit'));
        $this->assertStringContainsString('$eventId', $this->body('handleNew'));
    }
```

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add db.php events-lib.php tech-sheets.php tests/EventsLibTest.php tests/TechSheetsHandlersTest.php
git commit -m "feat(hub): event tagging and at-track choices"
```

---

### Task 4: The readiness engine

**Files:**
- Create: `readiness-lib.php`, `tests/ReadinessTest.php`

**Interfaces:**
- Consumes: `techCarStatus()`, `techSeasonFromDate()` (tech-status.php), `gearStatus()` (gear-lib.php)
- Produces: `buildReadiness(array $in): array`, a pure function.

**Input keys** (all required):

| Key | Contents |
|---|---|
| `today` | `'Y-m-d'` |
| `cars` | `id => car row` (active cars only: `id`, `car_number`, `year`, `make`, `model`) |
| `events` | active event rows (`id`, `name`, `event_date`) |
| `plans` | a list of `{event_id, car_id}` |
| `declarations` | `car_id => current declaration row` (`review_status`, `submitted_at`, `calculated_class`) |
| `sheets` | the user's tech sheet rows (`id`, `car_id`, `event_id`, `season`, `status`, `photo_status`, `accepted_via`, `driver_id`) |
| `sheetDrivers` | `sheet_id => list of driver_id` (additional drivers) |
| `drivers` | `driver_id => driver row` (`id`, `name`) |
| `selfDriverId` | int |
| `gear` | `"driverId:season" => gear record row` |
| `atTrack` | a list of `"car:ID"` / `"driver:ID"` keys |

**Output:**

```php
[
  'events'   => [ ['event' => row, 'items' => [item, ...]], ... ],  // tagged upcoming events, soonest first
  'untagged' => [ event row, ... ],                                  // upcoming active events with no tagged car
]
```

Each `item` is:

```php
['kind' => 'declaration'|'tech_sheet'|'car_tech'|'gear', 'subject_type' => 'car'|'driver', 'subject_id' => int,
 'state' => 'todo'|'info'|'done', 'label' => string, 'detail' => string,
 'action' => ?array{label: string, url: string},
 'at_track' => ?array{subject_type: string, subject_id: int, season: int}]   // offer "I'll do it at the track"
```

**Rules** (spec §3):
- "Upcoming" means `event_date >= today`.
- The season of an event is `techSeasonFromDate(event_date)`.
- For each tagged upcoming event, in date order, for each tagged car (in `cars` order), emit:
  - the **declaration**, **car tech** and **gear** items. These are seasonal: each (kind, subject, season) is emitted only once, under the first event where it appears.
  - the **tech sheet** item, which is emitted for every event.
- **Declaration** (for car C, season S):
  - The current declaration is `accepted` and dated in S (by the year of `submitted_at`): **done**, "Class declared: {class}".
  - It's `submitted` and dated in S: **info**, "Class declaration for #N is with an inspector".
  - It's `needs_changes`: **todo**, "Your class declaration for #N needs changes", action "Re-declare class" → `calculator.php?car=C`.
  - There's none, or it's from an earlier season: **todo**, "Declare class for #N", action "Declare class" → `calculator.php?car=C`.
- **Tech sheet** (car C, event E):
  - A sheet exists for C+E: **done**, "Tech sheet for {event name} submitted".
  - Otherwise: **todo**, "Submit a tech sheet for #N", detail "Every car needs a tech sheet for every event.", action "Submit tech sheet" → `tech-sheets.php?action=new&car_id=C&event_id=E`.
- **Car tech** (car C, season S): compute `techCarStatus()` over the car's sheets with `season = S`.
  - `accepted`: **done**, "Car tech {S}: pre-teched", or "teched" when it was accepted in person.
  - `pending_review`: **info**, "Car tech photos for #N are with an inspector".
  - `needs_changes`: **todo**, "Retake photos for #N", action "Retake photos" → `tech-sheets.php?action=pretech&id={status.sheet_id}`.
  - `none` or `photos_draft`, and `car:C` is in `atTrack`: **done**, "Car tech for #N: you'll bring it to tech at the track".
  - `none` or `photos_draft`, otherwise: **todo**, "Car tech for #N", detail "Pre-tech with photos, or bring it to tech at the track.", and `at_track` = {car, C, S}.
    - The action is "Add photos" → `tech-sheets.php?action=pretech&id={latest season sheet id}` when the car has a sheet in S.
    - Without one, the action is `null` and the detail becomes "Pre-tech with photos after you submit the tech sheet, or bring it to tech at the track."
- **Gear** (driver D, season S):
  - The drivers for car C at event E are that sheet's `driver_id` plus its `sheetDrivers`, when a sheet exists for C+E. Otherwise it's `[selfDriverId]`.
  - Look up `gear["D:S"]`, apply `gearStatus()`, and use the same mapping as car tech with the name `{driver name}`:
    - todo label "Gear for {name}"
    - action "Add photos" → `gear.php?action=start&driver_id=D`
    - retake action → `gear.php?action=pretech&id={gear id}`
    - done label "Gear for {name}: pre-teched {S}" (or teched)
    - at-track done label "Gear for {name}: checked at the track"
    - `at_track` = {driver, D, S}.
- Car labels use `#{car_number}`. `detail` is `''` when not stated.

- [ ] **Step 1: Write the failing test**

`tests/ReadinessTest.php`:

```php
<?php
// wcma-calculator/tests/ReadinessTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessTest extends TestCase
{
    private function world(array $o = []): array {
        return array_merge([
            'today' => '2026-09-26',
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']],
            'events' => [
                ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2026-10-11'],
                ['id' => 11, 'name' => 'Season Finale', 'event_date' => '2026-10-25'],
                ['id' => 9, 'name' => 'Old Race', 'event_date' => '2026-08-01'],
            ],
            'plans' => [['event_id' => 10, 'car_id' => 3], ['event_id' => 11, 'car_id' => 3]],
            'declarations' => [3 => ['review_status' => 'accepted', 'submitted_at' => '2026-04-02 10:00:00', 'calculated_class' => 'GT3']],
            'sheets' => [],
            'sheetDrivers' => [],
            'drivers' => [5 => ['id' => 5, 'name' => 'Jordan Lee'], 6 => ['id' => 6, 'name' => 'Sam Patel']],
            'selfDriverId' => 5,
            'gear' => [],
            'atTrack' => [],
        ], $o);
    }

    private function items(array $r, int $eventIndex = 0): array {
        $out = [];
        foreach ($r['events'][$eventIndex]['items'] as $i) $out[$i['kind'] . ':' . $i['subject_id']] = $i;
        return $out;
    }

    public function testOnlyTaggedUpcomingEventsSoonestFirstAndUntaggedListed(): void
    {
        $r = buildReadiness($this->world(['plans' => [['event_id' => 11, 'car_id' => 3]]]));
        $this->assertSame([11], array_map(fn($e) => (int)$e['event']['id'], $r['events']));
        $this->assertSame([10], array_map(fn($e) => (int)$e['id'], $r['untagged']));   // past event 9 excluded
    }

    public function testSeasonalItemsOnlyUnderTheNearestEventAndSheetsUnderEach(): void
    {
        $r = buildReadiness($this->world());
        $first = array_keys($this->items($r, 0));
        $second = array_keys($this->items($r, 1));
        $this->assertEqualsCanonicalizing(['declaration:3', 'tech_sheet:3', 'car_tech:3', 'gear:5'], $first);
        $this->assertSame(['tech_sheet:3'], $second);
    }

    public function testDeclarationStates(): void
    {
        $state = fn(array $decl = null) => $this->items(buildReadiness($this->world(['declarations' => $decl === null ? [] : [3 => $decl]])))['declaration:3'];

        $this->assertSame('done', $state(['review_status' => 'accepted', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3'])['state']);
        $info = $state(['review_status' => 'submitted', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3']);
        $this->assertSame(['info', 'Class declaration for #42 is with an inspector'], [$info['state'], $info['label']]);
        $fix = $state(['review_status' => 'needs_changes', 'submitted_at' => '2026-04-02', 'calculated_class' => 'GT3']);
        $this->assertSame(['todo', 'calculator.php?car=3'], [$fix['state'], $fix['action']['url']]);
        $none = $state(null);
        $this->assertSame(['todo', 'Declare class for #42'], [$none['state'], $none['label']]);
        $old = $state(['review_status' => 'accepted', 'submitted_at' => '2025-05-01', 'calculated_class' => 'GT3']);
        $this->assertSame('todo', $old['state']);
    }

    public function testTechSheetTodoLinksToTheEventAndDoneWhenSubmitted(): void
    {
        $todo = $this->items(buildReadiness($this->world()))['tech_sheet:3'];
        $this->assertSame(['todo', 'tech-sheets.php?action=new&car_id=3&event_id=10'], [$todo['state'], $todo['action']['url']]);

        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $done = $this->items(buildReadiness($this->world(['sheets' => [$sheet]])))['tech_sheet:3'];
        $this->assertSame('done', $done['state']);
    }

    public function testCarTechStatesAndTheAtTrackChoice(): void
    {
        $sheet = fn(array $o) => array_merge(['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5], $o);
        $carTech = fn(array $w) => $this->items(buildReadiness($this->world($w)))['car_tech:3'];

        $noSheet = $carTech([]);
        $this->assertSame('todo', $noSheet['state']);
        $this->assertNull($noSheet['action']);
        $this->assertSame(['subject_type' => 'car', 'subject_id' => 3, 'season' => 2026], $noSheet['at_track']);

        $withSheet = $carTech(['sheets' => [$sheet([])]]);
        $this->assertSame('tech-sheets.php?action=pretech&id=70', $withSheet['action']['url']);

        $this->assertSame('done', $carTech(['atTrack' => ['car:3']])['state']);
        $this->assertSame('info', $carTech(['sheets' => [$sheet(['photo_status' => 'submitted'])]])['state']);
        $retake = $carTech(['sheets' => [$sheet(['photo_status' => 'needs_changes'])]]);
        $this->assertSame(['todo', 'tech-sheets.php?action=pretech&id=70'], [$retake['state'], $retake['action']['url']]);
        $this->assertSame('done', $carTech(['sheets' => [$sheet(['status' => 'teched', 'accepted_via' => 'photos'])]])['state']);
    }

    public function testGearUsesTheSheetsDriversOrTheSelfProfile(): void
    {
        $sheet = ['id' => 70, 'car_id' => 3, 'event_id' => 10, 'season' => 2026, 'status' => 'submitted', 'photo_status' => null, 'accepted_via' => null, 'driver_id' => 5];
        $r = buildReadiness($this->world(['sheets' => [$sheet], 'sheetDrivers' => [70 => [6]],
            'gear' => ['5:2026' => ['id' => 90, 'status' => 'accepted', 'accepted_via' => 'photos', 'photo_status' => 'accepted', 'season' => 2026]]]));
        $items = $this->items($r);
        $this->assertSame('done', $items['gear:5']['state']);
        $this->assertSame(['todo', 'gear.php?action=start&driver_id=6', 'Gear for Sam Patel'], [$items['gear:6']['state'], $items['gear:6']['action']['url'], $items['gear:6']['label']]);

        $selfOnly = $this->items(buildReadiness($this->world()));
        $this->assertArrayHasKey('gear:5', $selfOnly);
        $this->assertArrayNotHasKey('gear:6', $selfOnly);
    }

    public function testNothingTaggedMeansNoEventsAndAllUpcomingUntagged(): void
    {
        $r = buildReadiness($this->world(['plans' => []]));
        $this->assertSame([], $r['events']);
        $this->assertSame([10, 11], array_map(fn($e) => (int)$e['id'], $r['untagged']));
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', file_get_contents(__DIR__ . '/../readiness-lib.php'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter ReadinessTest`. Expected: FAIL (file missing).

- [ ] **Step 3: Implement `readiness-lib.php`**

```php
<?php
// wcma-calculator/readiness-lib.php
//
// What a competitor still has to do for the events they tagged (spec §3). buildReadiness() is
// pure: no DB, no HTML. loadReadinessInputs() (Task 5) gathers its input from the database.
// Callers must have loaded tech-status.php and gear-lib.php.

function readinessItem(string $kind, string $subjectType, int $subjectId, string $state, string $label,
                       string $detail = '', ?array $action = null, ?array $atTrack = null): array {
    return ['kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'state' => $state,
            'label' => $label, 'detail' => $detail, 'action' => $action, 'at_track' => $atTrack];
}

function readinessDeclaration(array $car, ?array $decl, int $season): array {
    $id = (int)$car['id'];
    $n = '#' . $car['car_number'];
    $redeclare = ['label' => 'Re-declare class', 'url' => 'calculator.php?car=' . $id];
    $inSeason = $decl !== null && (int)substr((string)$decl['submitted_at'], 0, 4) === $season;
    if ($decl !== null && $decl['review_status'] === 'needs_changes') {
        return readinessItem('declaration', 'car', $id, 'todo', "Your class declaration for $n needs changes", 'An inspector asked for changes.', $redeclare);
    }
    if (!$inSeason) {
        return readinessItem('declaration', 'car', $id, 'todo', "Declare class for $n", 'Declare once a season, or whenever the car changes.', ['label' => 'Declare class', 'url' => 'calculator.php?car=' . $id]);
    }
    if ($decl['review_status'] === 'accepted') {
        return readinessItem('declaration', 'car', $id, 'done', "$n class declared: " . $decl['calculated_class']);
    }
    return readinessItem('declaration', 'car', $id, 'info', "Class declaration for $n is with an inspector");
}

/**
 * Car tech or gear, which share one status model. $status is techCarStatus()/gearStatus() output.
 * $names: label (todo), doneLabel (sprintf with via word + season), atTrackLabel, retakeLabel.
 */
function readinessTech(string $kind, string $subjectType, int $subjectId, array $status, int $season,
                       bool $atTrack, array $names, ?string $photosUrl, ?string $retakeUrl): array {
    switch ($status['state']) {
        case 'accepted':
            $via = ($status['via'] ?? 'in_person') === 'photos' ? 'pre-teched' : 'teched';
            return readinessItem($kind, $subjectType, $subjectId, 'done', sprintf($names['doneLabel'], $via, $season));
        case 'pending_review':
            return readinessItem($kind, $subjectType, $subjectId, 'info', $names['pendingLabel']);
        case 'needs_changes':
            return readinessItem($kind, $subjectType, $subjectId, 'todo', $names['retakeLabel'], 'An inspector asked for some photos to be retaken.',
                $retakeUrl !== null ? ['label' => 'Retake photos', 'url' => $retakeUrl] : null);
    }
    if ($atTrack) {
        return readinessItem($kind, $subjectType, $subjectId, 'done', $names['atTrackLabel']);
    }
    $detail = $photosUrl !== null
        ? 'Pre-tech with photos, or bring it to tech at the track.'
        : 'Pre-tech with photos after you submit the tech sheet, or bring it to tech at the track.';
    return readinessItem($kind, $subjectType, $subjectId, 'todo', $names['label'], $detail,
        $photosUrl !== null ? ['label' => 'Add photos', 'url' => $photosUrl] : null,
        ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'season' => $season]);
}

function buildReadiness(array $in): array {
    $today = (string)$in['today'];
    $upcoming = array_values(array_filter($in['events'], fn(array $e): bool => (string)$e['event_date'] >= $today));
    usort($upcoming, fn(array $a, array $b): int => strcmp((string)$a['event_date'], (string)$b['event_date']) ?: ((int)$a['id'] <=> (int)$b['id']));

    $carsByEvent = [];
    foreach ($in['plans'] as $p) {
        if (isset($in['cars'][(int)$p['car_id']])) $carsByEvent[(int)$p['event_id']][(int)$p['car_id']] = true;
    }
    $atTrack = array_flip($in['atTrack']);

    $sheetsByCar = [];
    foreach ($in['sheets'] as $s) $sheetsByCar[(int)$s['car_id']][] = $s;

    $seen = [];
    $events = [];
    $untagged = [];
    foreach ($upcoming as $event) {
        $eid = (int)$event['id'];
        if (empty($carsByEvent[$eid])) { $untagged[] = $event; continue; }
        $season = techSeasonFromDate((string)$event['event_date']);
        $items = [];
        foreach (array_keys($in['cars']) as $carId) {
            if (!isset($carsByEvent[$eid][$carId])) continue;
            $car = $in['cars'][$carId];
            $n = '#' . $car['car_number'];
            $seasonSheets = array_values(array_filter($sheetsByCar[$carId] ?? [], fn(array $s): bool => (int)$s['season'] === $season));
            $eventSheet = null;
            foreach ($sheetsByCar[$carId] ?? [] as $s) {
                if ((int)$s['event_id'] === $eid) { $eventSheet = $s; break; }
            }

            $once = function (string $key) use (&$seen, $season): bool {
                $k = $key . '@' . $season;
                if (isset($seen[$k])) return false;
                return $seen[$k] = true;
            };

            if ($once("declaration:$carId")) {
                $items[] = readinessDeclaration($car, $in['declarations'][$carId] ?? null, $season);
            }

            $items[] = $eventSheet !== null
                ? readinessItem('tech_sheet', 'car', $carId, 'done', 'Tech sheet for ' . $event['name'] . ' submitted')
                : readinessItem('tech_sheet', 'car', $carId, 'todo', "Submit a tech sheet for $n", 'Every car needs a tech sheet for every event.',
                    ['label' => 'Submit tech sheet', 'url' => "tech-sheets.php?action=new&car_id=$carId&event_id=$eid"]);

            if ($once("car_tech:$carId")) {
                $status = techCarStatus($seasonSheets);
                $latest = $seasonSheets ? (int)end($seasonSheets)['id'] : null;
                $items[] = readinessTech('car_tech', 'car', $carId, $status, $season, isset($atTrack["car:$carId"]), [
                    'label' => "Car tech for $n", 'doneLabel' => "Car tech $season for $n: %s", 'pendingLabel' => "Car tech photos for $n are with an inspector",
                    'retakeLabel' => "Retake photos for $n", 'atTrackLabel' => "Car tech for $n: you'll bring it to tech at the track",
                ], $latest !== null ? 'tech-sheets.php?action=pretech&id=' . $latest : null,
                   $status['sheet_id'] !== null ? 'tech-sheets.php?action=pretech&id=' . $status['sheet_id'] : null);
            }

            $driverIds = $eventSheet !== null
                ? array_values(array_unique(array_filter(array_merge([(int)($eventSheet['driver_id'] ?? 0)], array_map('intval', $in['sheetDrivers'][(int)$eventSheet['id']] ?? [])))))
                : [(int)$in['selfDriverId']];
            foreach ($driverIds as $did) {
                if (!isset($in['drivers'][$did]) || !$once("gear:$did")) continue;
                $name = (string)$in['drivers'][$did]['name'];
                $gear = $in['gear']["$did:$season"] ?? null;
                $status = $gear !== null ? gearStatus($gear) : ['state' => 'none', 'via' => null];
                $items[] = readinessTech('gear', 'driver', $did, $status, $season, isset($atTrack["driver:$did"]), [
                    'label' => "Gear for $name", 'doneLabel' => "Gear for $name: %s $season", 'pendingLabel' => "Gear photos for $name are with an inspector",
                    'retakeLabel' => "Retake gear photos for $name", 'atTrackLabel' => "Gear for $name: checked at the track",
                ], 'gear.php?action=start&driver_id=' . $did, $gear !== null ? 'gear.php?action=pretech&id=' . (int)$gear['id'] : null);
            }
        }
        $events[] = ['event' => $event, 'items' => $items];
    }
    return ['events' => $events, 'untagged' => $untagged];
}
```

Note `doneLabel` uses `sprintf`, so the car label reads e.g. "Car tech 2026 for #42: pre-teched". The test only asserts the done **state**, not the text.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar --filter ReadinessTest`, then `php phpunit.phar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add readiness-lib.php tests/ReadinessTest.php
git commit -m "feat(hub): readiness engine for tagged events"
```

---

### Task 5: Readiness input loader and gear start route

**Files:**
- Modify: `readiness-lib.php` (add `loadReadinessInputs()`)
- Modify: `gear.php` (the `start` action)
- Create: `tests/ReadinessLoaderTest.php`

**Interfaces:**
- Consumes: Tasks 3–4, and Phase 1 DB functions
- Produces:
  - `loadReadinessInputs(PDO $pdo, int $userId, string $today): array`, which returns exactly the `buildReadiness()` input shape.
  - `gear.php?action=start&driver_id=D` (signed in, GET): the driver must belong to the user. It finds or creates this season's gear record (via `gearCreate($pdo, $userId, $driver['name'], '', gearSeasonNow())` when missing), then redirects to `gear.php?action=pretech&id={id}`. A driver that isn't theirs gets the flash "Driver not found." and a redirect to `gear.php`.

- [ ] **Step 1: Write the failing test**

`tests/ReadinessLoaderTest.php`:

```php
<?php
// wcma-calculator/tests/ReadinessLoaderTest.php
require_once __DIR__ . '/../tech-status.php';
require_once __DIR__ . '/../gear-lib.php';
require_once __DIR__ . '/../readiness-lib.php';

use PHPUnit\Framework\TestCase;

final class ReadinessLoaderTest extends TestCase
{
    public function testLoaderBuildsTheInputShapeFromTheDatabase(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $sub = db_insert_submission($pdo, test_declaration_data($pdo, $u, '42'));
        $car = test_make_car($pdo, $u, '42');
        $archived = test_make_car($pdo, $u, '9');
        db_archive_car($pdo, $u, $archived);
        $event = db_create_event($pdo, 'Fall Sprint', '2026-10-11', null);
        db_tag_event($pdo, $u, $event, $car);
        $sheet = db_insert_tech_sheet($pdo, [
            'submission_id' => $sub, 'user_id' => $u, 'event_id' => $event, 'sheet_type' => 'endurance',
            'entrant_name' => 'Team', 'driver_name' => 'Jordan Lee', 'car_make' => 'Mazda', 'car_model' => 'MX-5',
            'car_colour' => 'Red', 'car_number' => '42', 'class' => 'IT1', 'engine_cc' => null, 'engine_hp' => null,
            'car_weight' => 2200, 'checklist_json' => '{}', 'driver1_equipment_json' => '{}', 'log_book_turned_in' => 1,
        ]);
        db_add_tech_sheet_driver($pdo, $sheet, 2, 'Sam Patel', '{}');
        gearCreate($pdo, $u, 'Jordan Lee', '', 2026);
        $self = (int)db_get_self_driver($pdo, $u)['id'];
        db_set_at_track($pdo, 'car', $car, 2026);

        $in = loadReadinessInputs($pdo, $u, '2026-09-26');

        $this->assertSame([$car], array_keys($in['cars']));                     // archived car excluded
        $this->assertSame('2026-09-26', $in['today']);
        $this->assertSame([['event_id' => $event, 'car_id' => $car]], $in['plans']);
        $this->assertSame('submitted', $in['declarations'][$car]['review_status']);
        $this->assertCount(1, $in['sheets']);
        $sam = (int)db_find_driver($pdo, $u, 'Sam Patel')['id'];
        $this->assertSame([$sam], $in['sheetDrivers'][$sheet]);
        $this->assertSame($self, $in['selfDriverId']);
        $this->assertArrayHasKey($sam, $in['drivers']);
        $this->assertArrayHasKey("$self:2026", $in['gear']);
        $this->assertSame(["car:$car"], $in['atTrack']);

        $result = buildReadiness($in);   // the shape is directly usable
        $this->assertCount(1, $result['events']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter ReadinessLoaderTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

Append to `readiness-lib.php`:

```php
/** Gathers buildReadiness() input for one user from the database. Callers must have loaded db.php. */
function loadReadinessInputs(PDO $pdo, int $userId, string $today): array {
    $cars = [];
    foreach (db_get_user_cars($pdo, $userId) as $c) $cars[(int)$c['id']] = $c;

    $sheets = db_get_user_tech_sheets($pdo, $userId);
    $sheetDrivers = [];
    foreach (db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $sheets)) as $sheetId => $rows) {
        $sheetDrivers[(int)$sheetId] = array_values(array_filter(array_map(fn(array $r): int => (int)($r['driver_id'] ?? 0), $rows)));
    }

    $drivers = [];
    foreach (db_get_user_drivers($pdo, $userId) as $d) $drivers[(int)$d['id']] = $d;
    $self = db_get_self_driver($pdo, $userId);

    $events = db_get_active_events($pdo);
    $seasons = array_values(array_unique(array_map(fn(array $e): int => techSeasonFromDate((string)$e['event_date']), $events)));
    $gear = [];
    foreach (array_keys($drivers) as $did) {
        foreach ($seasons as $season) {
            $g = db_get_gear_record_for_driver($pdo, $did, $season);
            if ($g !== null) $gear["$did:$season"] = $g;
        }
    }

    $atTrack = [];
    foreach ($seasons as $season) {
        $atTrack = array_merge($atTrack, db_get_at_track_keys($pdo, array_keys($cars), array_keys($drivers), $season));
    }

    return [
        'today' => $today,
        'cars' => $cars,
        'events' => $events,
        'plans' => array_map(fn(array $p): array => ['event_id' => (int)$p['event_id'], 'car_id' => (int)$p['car_id']], db_get_user_event_plans($pdo, $userId)),
        'declarations' => db_get_user_current_declarations($pdo, $userId),
        'sheets' => $sheets,
        'sheetDrivers' => $sheetDrivers,
        'drivers' => $drivers,
        'selfDriverId' => $self !== null ? (int)$self['id'] : 0,
        'gear' => $gear,
        'atTrack' => array_values(array_unique($atTrack)),
    ];
}
```

(`db_get_at_track_keys()` is filtered by season, so a key from a different season can't mark this season done. `buildReadiness()` checks keys without a season, which is fine when the active events fall in one season. It's documented here because events spanning two seasons with at-track choices is a rare edge case accepted for Phase 2.)

`gear.php`: add a router case and handler:

```php
    case 'start':
        $user = requireGearLogin();
        handleGearStart($pdo, $user, (int)($_GET['driver_id'] ?? 0));
        break;
```

```php
/** Opens this season's gear photos for one of the user's drivers, creating the season's record if needed. */
function handleGearStart(PDO $pdo, array $user, int $driverId): void {
    $driver = db_get_driver($pdo, $driverId);
    if ($driver === null || (int)$driver['owner_user_id'] !== (int)$user['id']) {
        setFlash('Driver not found.', 'error');
        header('Location: gear.php');
        exit;
    }
    $season = gearSeasonNow();
    $gear = db_get_gear_record_for_driver($pdo, $driverId, $season);
    if ($gear === null) {
        $r = gearCreate($pdo, (int)$user['id'], (string)$driver['name'], '', $season);
        if (!$r['ok']) { setFlash((string)$r['error'], 'error'); header('Location: gear.php'); exit; }
        $id = (int)$r['id'];
    } else {
        $id = (int)$gear['id'];
    }
    header('Location: gear.php?action=pretech&id=' . $id);
    exit;
}
```

Add a source-level guard to a new `tests/GearStartSourceTest.php`: the `handleGearStart` body contains `owner_user_id` and `db_get_gear_record_for_driver(`. Follow the `body()` helper pattern in `tests/TechSheetsHandlersTest.php`.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add readiness-lib.php gear.php tests/ReadinessLoaderTest.php tests/GearStartSourceTest.php
git commit -m "feat(hub): readiness input loader and gear start route"
```

---

### Task 6: Landing page and Home

**Files:**
- Create: `home-page.php`, `tests/HomePageTest.php`
- Rewrite: `index.php`

**Interfaces:**
- Consumes:
  - `buildReadiness()` / `loadReadinessInputs()` (Tasks 4–5)
  - `eventsTagCar()` / `eventsUntagCar()` / `eventsSetAtTrack()` and `EVENTS_NOT_REGISTERING` (Task 3)
  - `db_get_season_links()`, `carDisplayName()`, `declarationReviewLabel()`, `declarationReviewBadgeClass()`, `techCarStatus()`, `techCarStatusLabel()`, `gearStatus()`, `gearStatusLabel()`, `renderPageStart()` / `renderPageEnd()`
- Produces (`home-page.php`, pure: returns strings and never echoes):
  - `renderLandingHtml(array $seasonLinks): string`
  - `renderHomeHtml(array $vm): string`. `$vm` keys:
    - `name`
    - `readiness` (the `buildReadiness()` output)
    - `cars` (id => row)
    - `garage` (a list of `['car' => row, 'declaration' => ?row, 'techLabel' => string, 'techState' => string]`)
    - `drivers` (a list of `['name' => string, 'isSelf' => bool, 'gearLabel' => string, 'gearState' => string]`)
    - `seasonLinks`
    - `csrf`
  - `homeHeadline(array $readiness): string`

**`index.php` routes:**
- GET, signed out → the landing page.
- GET, signed in → Home.
- POST (signed in, CSRF-checked; every one redirects back to `index.php` with a flash):
  - `action=tag` (`event_id`, `car_id`)
  - `action=untag` (`event_id`, `car_id`)
  - `action=at-track` (`subject_type`, `subject_id`, `season`)

**Home layout** (spec §3, mockup C):
1. `<h1>` from `homeHeadline()`:
   - no tagged events → "Which events are you going to?"
   - todo count 0 for the first event → "You're all set for {name} ✓"
   - otherwise "{N} thing(s) to do before {name}", followed by a line with the date and "in N days".
2. `<ol class="hub-todo">`: every `todo` item across the **first** event, numbered.
   - The action is a `hub-btn` link.
   - When `at_track` is set, add a small POST form button "I'll do it at the track" (`hub-btn hub-btn--link`).
3. "With an inspector": the `info` items of the first event, as a `hub-card` list.
4. `<details class="hub-done" open>`: "Already done for {event} (n)" with the `done` items.
5. "Upcoming events: are you going?": the untagged events, each with a POST form holding a car `<select>` (or a hidden `car_id` when there's one car), an "I'm going" button, and the `EVENTS_NOT_REGISTERING` sentence.
   - Tagged events in the same section get a "Not going anymore" untag form per car. To keep this simple, list them under "Events you're going to" with each tagged car's plate.
6. "At a glance": `hub-grid-2` with a Garage card (plate, car name, class badge + review label, tech status word) linking "Open garage →" (`account.php`), and a Drivers card linking "Manage drivers →" (`gear.php`).
7. "This season on MotorsportReg": the season links, each `target="_blank" rel="noopener"`, **escaped**. Omit the section when there are none.
8. "Next after that: {second event}, N things to do.", only when a second tagged event exists.
9. **Empty states:**
   - no cars → a `hub-card`: "Start by declaring your class", with a `hub-btn` to `calculator.php`
   - no active events → "No upcoming events yet."

**Landing** (signed out), a `hub-hero` plus cards:
- "The WCMA Hub: declare your class, submit tech sheets and track car and gear tech for the season."
- Buttons: "Class Calculator" (`calculator.php`), "Sign in" (`auth.php?action=login&redirect=index.php`), "Create account" (`auth.php?action=register&redirect=index.php`).
- The MotorsportReg links card.

- [ ] **Step 1: Write the failing test**

`tests/HomePageTest.php`:

```php
<?php
// wcma-calculator/tests/HomePageTest.php
require_once __DIR__ . '/../view_helpers.php';
require_once __DIR__ . '/../cars-lib.php';
require_once __DIR__ . '/../events-lib.php';
require_once __DIR__ . '/../home-page.php';

use PHPUnit\Framework\TestCase;

final class HomePageTest extends TestCase
{
    private function item(string $kind, int $id, string $state, string $label, ?array $action = null, ?array $atTrack = null): array {
        return ['kind' => $kind, 'subject_type' => $kind === 'gear' ? 'driver' : 'car', 'subject_id' => $id, 'state' => $state,
                'label' => $label, 'detail' => '', 'action' => $action, 'at_track' => $atTrack];
    }

    private function vm(array $o = []): array {
        return array_merge([
            'name' => 'Jordan Lee',
            'readiness' => ['events' => [[
                'event' => ['id' => 10, 'name' => 'Fall Sprint', 'event_date' => '2099-10-11'],
                'items' => [
                    $this->item('tech_sheet', 3, 'todo', 'Submit a tech sheet for #42', ['label' => 'Submit tech sheet', 'url' => 'tech-sheets.php?action=new&car_id=3&event_id=10']),
                    $this->item('car_tech', 3, 'todo', 'Car tech for #42', null, ['subject_type' => 'car', 'subject_id' => 3, 'season' => 2099]),
                    $this->item('declaration', 3, 'info', 'Class declaration for #42 is with an inspector'),
                    $this->item('gear', 5, 'done', 'Gear for Jordan Lee: pre-teched 2099'),
                ],
            ]], 'untagged' => [['id' => 11, 'name' => 'Season Finale', 'event_date' => '2099-10-25']]],
            'cars' => [3 => ['id' => 3, 'car_number' => '42', 'year' => '2004', 'make' => 'Honda', 'model' => 'S2000']],
            'garage' => [],
            'drivers' => [],
            'seasonLinks' => [['label' => 'Waiver <2026>', 'url' => 'https://x.test/a?b=1&c=2']],
            'csrf' => 'tok',
        ], $o);
    }

    public function testHeadlineVariants(): void
    {
        $r = $this->vm()['readiness'];
        $this->assertSame('2 things to do before Fall Sprint', homeHeadline($r));
        $r['events'][0]['items'] = array_values(array_filter($r['events'][0]['items'], fn($i) => $i['state'] !== 'todo'));
        $this->assertSame('You\'re all set for Fall Sprint ✓', homeHeadline($r));
        $this->assertSame('Which events are you going to?', homeHeadline(['events' => [], 'untagged' => []]));
        $one = $this->vm()['readiness'];
        array_pop($one['events'][0]['items']); array_pop($one['events'][0]['items']); array_pop($one['events'][0]['items']);
        $this->assertSame('1 thing to do before Fall Sprint', homeHeadline($one));
    }

    public function testHomeRendersNumberedTodosSecondaryActionAndSections(): void
    {
        $html = renderHomeHtml($this->vm());
        $this->assertStringContainsString('class="hub-todo"', $html);
        $this->assertStringContainsString('href="tech-sheets.php?action=new&amp;car_id=3&amp;event_id=10"', $html);
        $this->assertStringContainsString("I'll do it at the track", $html);
        $this->assertStringContainsString('name="action" value="at-track"', $html);
        $this->assertStringContainsString('name="csrf_token" value="tok"', $html);
        $this->assertStringContainsString('With an inspector', $html);
        $this->assertStringContainsString('Already done for Fall Sprint (1)', $html);
        $this->assertStringContainsString('Season Finale', $html);
        $this->assertStringContainsString("I'm going", $html);
        $this->assertStringContainsString(EVENTS_NOT_REGISTERING, $html);
    }

    public function testSeasonLinksAreEscapedAndOmittedWhenEmpty(): void
    {
        $html = renderHomeHtml($this->vm());
        $this->assertStringContainsString('Waiver &lt;2026&gt;', $html);
        $this->assertStringContainsString('href="https://x.test/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringNotContainsString('MotorsportReg', renderHomeHtml($this->vm(['seasonLinks' => []])));
    }

    public function testEmptyStates(): void
    {
        $noCars = renderHomeHtml($this->vm(['cars' => [], 'readiness' => ['events' => [], 'untagged' => []]]));
        $this->assertStringContainsString('Start by declaring your class', $noCars);
        $this->assertStringContainsString('href="calculator.php"', $noCars);
        $noEvents = renderHomeHtml($this->vm(['readiness' => ['events' => [], 'untagged' => []]]));
        $this->assertStringContainsString('No upcoming events yet.', $noEvents);
    }

    public function testLandingOffersCalculatorAndSignIn(): void
    {
        $html = renderLandingHtml([['label' => 'Waiver', 'url' => 'https://x.test']]);
        foreach (['href="calculator.php"', 'action=login', 'action=register', 'Waiver'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function testNoBannedWording(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(approved|approval|passed|safe)\b/i', file_get_contents(__DIR__ . '/../home-page.php'));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter HomePageTest`. Expected: FAIL.

- [ ] **Step 3: Implement `home-page.php`**

Build `home-page.php` with the functions listed under Interfaces. Requirements the test does not spell out:
- Every dynamic value goes through `h()`, including URLs in `href`.
- Every POST form includes `<input type="hidden" name="csrf_token" value="…">` and posts to `index.php`.
- The at-track form posts `action=at-track` plus `subject_type`, `subject_id` and `season` from the item's `at_track`.
- The tag form posts `action=tag` with `event_id`, and either a `<select name="car_id">` over `$vm['cars']` (label `carDisplayName()`) or a hidden `car_id` for a single car. The `EVENTS_NOT_REGISTERING` sentence sits in a `<p class="form-hint">` under the list.
- The first event's date line reads "`{l, F j}` · in N days" (from `date_diff` against today), for example "Saturday, October 11 · in 17 days".
- Status words in the Garage and Drivers cards use `<span class="hub-status hub-status--{ok|warn|todo|info}">`. The mapping: `accepted` → ok, `needs_changes` → todo, `pending_review`/`submitted` → info, anything else → warn.
- `homeHeadline()` pluralises "thing"/"things".

`index.php` (full file):

```php
<?php
// wcma-calculator/index.php — the hub front door: landing page when signed out, Home when signed in.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/readiness-lib.php';
require __DIR__ . '/home-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_role('user');
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $uid = (int)$user['id'];
    switch ($_POST['action'] ?? '') {
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'untag':
            $r = eventsUntagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
            setFlash($r['ok'] ? 'Removed from your events.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'at-track':
            $r = eventsSetAtTrack($pdo, $uid, (string)($_POST['subject_type'] ?? ''), (int)($_POST['subject_id'] ?? 0), (int)($_POST['season'] ?? 0));
            setFlash($r['ok'] ? 'Noted: you\'ll get it checked at the track.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
    }
    header('Location: index.php');
    exit;
}

if ($user === null) {
    renderPageStart('Welcome', 'home');
    echo renderLandingHtml(db_get_season_links($pdo, true));
    renderPageEnd();
    exit;
}

$uid = (int)$user['id'];
$in = loadReadinessInputs($pdo, $uid, date('Y-m-d'));
$season = gearSeasonNow();

$garage = [];
foreach ($in['cars'] as $carId => $car) {
    $status = techCarStatus(db_get_identity_sheets($pdo, $carId, $season));
    $garage[] = ['car' => $car, 'declaration' => $in['declarations'][$carId] ?? null,
                 'techLabel' => techCarStatusLabel($status, $season), 'techState' => $status['state']];
}
$drivers = [];
foreach ($in['drivers'] as $did => $d) {
    $g = $in['gear']["$did:$season"] ?? null;
    $st = $g !== null ? gearStatus($g) : ['state' => 'none', 'via' => null];
    $drivers[] = ['name' => (string)$d['name'], 'isSelf' => $did === $in['selfDriverId'],
                  'gearLabel' => gearStatusLabel($st, $season), 'gearState' => $st['state']];
}

renderPageStart('Home', 'home', ['flash' => getFlash()]);
echo renderHomeHtml([
    'name' => (string)$user['name'], 'readiness' => buildReadiness($in), 'cars' => $in['cars'],
    'garage' => $garage, 'drivers' => $drivers, 'seasonLinks' => db_get_season_links($pdo, true),
    'csrf' => generateCsrfToken(),
]);
renderPageEnd();
```

`require_role('user')` (Phase 1) returns the user, or redirects to sign-in. `renderHomeHtml()` needs the tagged events for the "not going anymore" forms. Derive them from `$vm['readiness']['events']`: each event's cars are the distinct `subject_id` values of its `tech_sheet` items.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS. Run `php -l index.php home-page.php`.

- [ ] **Step 5: Commit**

```bash
git add home-page.php index.php tests/HomePageTest.php
git commit -m "feat(hub): landing page and signed-in Home"
```

---

### Task 7: The calculator becomes `calculator.php`

**Files:**
- Create: `calculator.php`, `tests/CalculatorPageTest.php`
- Rewrite: `car-classing.html` (a redirect)
- Modify: every link to `car-classing.html` (`grep -rln "car-classing.html" --include=*.php --include=*.js . | grep -v tests`), `auth.php` (`safeRedirectTarget()`)

**Interfaces:**
- Consumes: `renderPageStart()` / `renderPageEnd()`, `db_get_user_car()`, `carDisplayName()`
- Produces:
  - **`calculator.php`** renders the calculator markup (the contents of `car-classing.html`'s `.container`, from the two-step panel to `#account-nudge`, **minus** the old `<header>` and the inline nav `<script>`) between `renderPageStart('Class Calculator', 'calculator', ['bodyClass' => 'calculator-page'])` and `renderPageEnd(['scripts' => …])`. The scripts it passes are the existing ones: calculator.js, form-handler.js and ui-controller.js as modules with their `?v=` query strings, plus car-picker.js, with each `?v=` bumped by 0.1. Feedback and nav are already included by `renderPageEnd()`.
  - **Signed in:** the Name and Email form groups are rendered with `hidden` on their `.form-group` and `value="…"` set from the account (`db_find_user_by_id`), so client validation still passes.
  - **Signed in with `?car=C`** (an owned, active car):
    - a banner `<div class="hub-card calc-car-banner">Declaring class for <strong>{carDisplayName}</strong></div>` above the form
    - `<input type="hidden" name="car_id" value="C">` in place of the picker (don't render `#car-picker-group`, so car-picker.js does nothing)
    - `#car-number-group` is not rendered
    - the Year, Make and Model groups are rendered `hidden` with the car's values.
  - **Signed out:** the Name, Email, Year, Make and Model groups stay visible and editable, exactly as today.
  - **`car-classing.html`** becomes a minimal page that sends visitors on with `location.replace('calculator.php' + location.search)`, plus `<meta http-equiv="refresh" content="0; url=calculator.php">` and a plain link for no-JS.
  - **`safeRedirectTarget()`** allows `index.php`, `calculator.php` with optional `?car=\d+`, `?draft=\d+` or `?restore=1`, `profile.php`, `account.php` and `admin.php`. Its default becomes `index.php`.

- [ ] **Step 1: Write the failing test**

`tests/CalculatorPageTest.php`:

```php
<?php
// wcma-calculator/tests/CalculatorPageTest.php — source-level guards (calculator.php needs a session).
use PHPUnit\Framework\TestCase;

final class CalculatorPageTest extends TestCase
{
    private function src(string $f): string { return file_get_contents(__DIR__ . '/../' . $f); }

    public function testCalculatorUsesTheLayoutAndKeepsTheCalculatorScripts(): void
    {
        $src = $this->src('calculator.php');
        $this->assertStringContainsString("renderPageStart('Class Calculator', 'calculator'", $src);
        foreach (['js/calculator.js', 'js/form-handler.js', 'js/ui-controller.js', 'js/car-picker.js', 'id="classing-form"', 'id="competition-weight"', 'id="declared-hp"'] as $needle) {
            $this->assertStringContainsString($needle, $src, $needle);
        }
        $this->assertStringNotContainsString("fetch('session-status.php')\n        .then(function (res)", $src);   // old inline nav script gone
        $this->assertStringContainsString('db_get_user_car(', $src);
        $this->assertStringContainsString('calc-car-banner', $src);
    }

    public function testOldUrlRedirectsKeepingTheQueryString(): void
    {
        $html = $this->src('car-classing.html');
        $this->assertStringContainsString("location.replace('calculator.php' + location.search)", $html);
        $this->assertStringContainsString('url=calculator.php', $html);
        $this->assertStringNotContainsString('classing-form', $html);
    }

    public function testNoCodeStillLinksToTheOldPage(): void
    {
        foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../js/*.js')) as $f) {
            $this->assertStringNotContainsString('car-classing.html', file_get_contents($f), basename($f));
        }
    }

    public function testRedirectWhitelist(): void
    {
        require_once __DIR__ . '/../view_helpers.php';
        $src = $this->src('auth.php');
        $start = strpos($src, 'function safeRedirectTarget');
        $end = strpos($src, "\n}\n", $start);
        eval(substr($src, $start, $end - $start + 2));
        foreach (['index.php', 'calculator.php', 'calculator.php?car=3', 'calculator.php?restore=1', 'calculator.php?draft=9', 'profile.php', 'account.php', 'admin.php'] as $ok) {
            $this->assertSame($ok, safeRedirectTarget($ok), $ok);
        }
        foreach (['https://evil.test', '//evil.test', 'calculator.php?car=x', 'car-classing.html'] as $bad) {
            $this->assertSame('index.php', safeRedirectTarget($bad), $bad);
        }
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter CalculatorPageTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

`calculator.php`: the header is:

```php
<?php
// wcma-calculator/calculator.php — the Class Calculator inside the hub layout.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';

$pdo = db_connect();
db_init($pdo);
$user = current_user();
$account = $user !== null ? db_find_user_by_id($pdo, (int)$user['id']) : null;
$boundCar = null;
if ($user !== null && isset($_GET['car']) && ctype_digit((string)$_GET['car'])) {
    $c = db_get_user_car($pdo, (int)$user['id'], (int)$_GET['car']);
    if ($c !== null && $c['archived_at'] === null) $boundCar = $c;
}
$v = fn(?string $s): string => h((string)$s);
```

Then call `renderPageStart(...)` and write out the calculator markup from `car-classing.html`, with PHP conditionals for the account and car-bound fields described under Interfaces. Moving it is a copy of the markup: every `id`, `name` and `class` stays byte-identical, so `ui-controller.js` keeps working.

Rewrite `car-classing.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="0; url=calculator.php">
<title>Class Calculator moved — WCMA Hub</title>
<script>location.replace('calculator.php' + location.search);</script>
</head>
<body><p>The Class Calculator has moved. <a href="calculator.php">Open the Class Calculator</a>.</p></body>
</html>
```

`auth.php` `safeRedirectTarget()`:

```php
function safeRedirectTarget(?string $raw): string {
    $raw = trim((string)$raw);
    if (preg_match('/^(index\.php|calculator\.php(\?(car|draft)=\d+|\?restore=1)?|profile\.php|account\.php|admin\.php)$/', $raw)) {
        return $raw;
    }
    return 'index.php';
}
```

Replace every other `car-classing.html` in `*.php`/`js/*.js` with `calculator.php`. Keep the `?draft=`/`?car=` query parts: `account.php` links, `tech-sheets.php` redirects, `index.php`, `view_helpers.php`, `js/form-handler.js`, `js/ui-controller.js` and `admin.php`'s unauthorised redirect.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar` and `node --test tests/js/*.test.js`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A calculator.php car-classing.html auth.php account.php tech-sheets.php view_helpers.php admin.php js/ tests/CalculatorPageTest.php
git commit -m "feat(hub): the Class Calculator moves into the hub layout"
```

---

### Task 8: Pre-fill from a car's declaration and keep a visitor's entries across sign-in

**Files:**
- Modify: `db.php` (`submissions.form_data` column; `db_insert_submission()` stores it when given)
- Modify: `car-classing.php` (stores the posted `form_data`)
- Modify: `cars.php` (`action=declaration&car_id=C`)
- Create: `js/declaration-state.js`, `tests/js/declaration-state.test.js`, `tests/DeclarationFormDataTest.php`
- Modify: `js/ui-controller.js` (extract `applyFormData()`; load on `?car=` and `?restore=1`; add `form_data` on submit)
- Modify: `js/form-handler.js` (on 401, stash and redirect)

**Interfaces:**
- **`db.php`:**
  - `submissions` gains `form_data TEXT`.
  - `db_insert_submission()` accepts an optional `':form_data'` key. When absent, it binds `null`: build the SQL with the column, set `$data[':form_data'] ??= null` before executing, and keep every other rule from Phase 1.
  - `db_get_car_declaration_form(PDO $pdo, int $userId, int $carId): ?array` returns the decoded `form_data` of the car's current declaration, or `null` when the car isn't the user's, there's no declaration, or it has no form data.
- **`car-classing.php`:** passes `':form_data' => (is_array(json_decode($_POST['form_data'] ?? '', true)) ? $_POST['form_data'] : null)`.
- **`cars.php`:** `?action=declaration&car_id=C` → `{success: true, form_data: {...}|null}`, still 401 when signed out. The list stays the default.
- **`js/declaration-state.js`** (CommonJS-guarded like `car-picker.js`):
  - `STASH_KEY = 'wcma-pending-declaration'`
  - `stashForm(storage, data): boolean`: `JSON.stringify` into `storage.setItem`. It returns `false` if storage throws.
  - `takeStashedForm(storage): object|null`: reads, parses, removes, and returns `null` on any error or missing value.
  - `signInUrlForRestore(): string` → `'auth.php?action=login&redirect=' + encodeURIComponent('calculator.php?restore=1')`
- **`js/ui-controller.js`:**
  - `applyFormData(data, message)` is the body of `loadConfiguration()` from "Populate all form fields" to the end, with `showMessage(message, 'success')` in place of the fixed text. `loadConfiguration()` then calls `applyFormData(data, 'Configuration loaded successfully!')`.
  - In `initialize()`:
    - with `?car=` → fetch `cars.php?action=declaration&car_id=…`; on `form_data`, `applyFormData(form_data, 'Loaded your current class declaration for this car. Change anything that is different and submit.')`
    - with `?restore=1` → `takeStashedForm(sessionStorage)`, and `applyFormData(data, 'Your entries are back. Check them and submit.')` when non-null.
  - Before submit, add a hidden `form_data` input holding `JSON.stringify(getAllFormDataForSave())`, alongside the existing hidden fields.
- **`js/form-handler.js`:** in `handleFormSubmit()`, when `response.status === 401`:
  - `stashForm(sessionStorage, dataForStash)`, where the caller passes the data. Give `handleFormSubmit(form, onSubmitCallback = null, stashData = null)` a third parameter, and have ui-controller pass `getAllFormDataForSave()`.
  - Show "Sign in or create a free account to submit your class declaration. Your entries will be kept."
  - Redirect to `signInUrlForRestore()` after 1.5 seconds.
  - Load `declaration-state.js` before the module scripts in `calculator.php`. It's a classic script exposing `window.WcmaDeclarationState`; the modules read it from `window`.

- [ ] **Step 1: Write the failing tests**

`tests/js/declaration-state.test.js`:

```js
const test = require('node:test');
const assert = require('node:assert');
const { STASH_KEY, stashForm, takeStashedForm, signInUrlForRestore } = require('../../js/declaration-state.js');

function memoryStorage() {
    const m = new Map();
    return { setItem: (k, v) => m.set(k, String(v)), getItem: k => (m.has(k) ? m.get(k) : null), removeItem: k => m.delete(k), _m: m };
}

test('stash then take returns the data once', () => {
    const s = memoryStorage();
    assert.strictEqual(stashForm(s, { competitionWeight: '2860' }), true);
    assert.deepStrictEqual(takeStashedForm(s), { competitionWeight: '2860' });
    assert.strictEqual(takeStashedForm(s), null);
    assert.strictEqual(s._m.has(STASH_KEY), false);
});

test('storage failures never throw', () => {
    const broken = { setItem() { throw new Error('quota'); }, getItem() { throw new Error('denied'); }, removeItem() {} };
    assert.strictEqual(stashForm(broken, { a: 1 }), false);
    assert.strictEqual(takeStashedForm(broken), null);
    const s = memoryStorage();
    s.setItem(STASH_KEY, '{not json');
    assert.strictEqual(takeStashedForm(s), null);
});

test('sign-in URL comes back to the restore page', () => {
    assert.strictEqual(signInUrlForRestore(), 'auth.php?action=login&redirect=calculator.php%3Frestore%3D1');
});
```

`tests/DeclarationFormDataTest.php`:

```php
<?php
// wcma-calculator/tests/DeclarationFormDataTest.php
use PHPUnit\Framework\TestCase;

final class DeclarationFormDataTest extends TestCase
{
    public function testFormDataIsStoredAndReturnedOnlyToTheOwner(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $other = db_create_user($pdo, ['email' => 'o@example.com', 'name' => 'Other Person', 'password_hash' => 'x', 'google_id' => null]);
        db_insert_submission($pdo, test_declaration_data($pdo, $u, '42', [':form_data' => json_encode(['competitionWeight' => '2860', 'chassis' => 'chassis3'])]));
        $car = test_make_car($pdo, $u, '42');

        $this->assertSame(['competitionWeight' => '2860', 'chassis' => 'chassis3'], db_get_car_declaration_form($pdo, $u, $car));
        $this->assertNull(db_get_car_declaration_form($pdo, $other, $car));
        $this->assertNull(db_get_car_declaration_form($pdo, $u, test_make_car($pdo, $u, '7')));
    }

    public function testDeclarationWithoutFormDataStillInserts(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => 'x', 'google_id' => null]);
        $id = db_insert_submission($pdo, test_declaration_data($pdo, $u));
        $this->assertNull(db_get_submission($pdo, $id)['form_data']);
    }
}
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `node --test tests/js/declaration-state.test.js` and `php phpunit.phar --filter DeclarationFormDataTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

`js/declaration-state.js`:

```js
// wcma-calculator/js/declaration-state.js
//
// Keeps a signed-out visitor's calculator entries across sign-in (sessionStorage), so declaring a
// class never loses their work. Classic script; CommonJS-guarded for node --test.
(function () {
    const STASH_KEY = 'wcma-pending-declaration';

    function stashForm(storage, data) {
        try { storage.setItem(STASH_KEY, JSON.stringify(data)); return true; } catch (e) { return false; }
    }

    function takeStashedForm(storage) {
        try {
            const raw = storage.getItem(STASH_KEY);
            storage.removeItem(STASH_KEY);
            if (raw === null) return null;
            const data = JSON.parse(raw);
            return data && typeof data === 'object' ? data : null;
        } catch (e) {
            return null;
        }
    }

    function signInUrlForRestore() {
        return 'auth.php?action=login&redirect=' + encodeURIComponent('calculator.php?restore=1');
    }

    const api = { STASH_KEY, stashForm, takeStashedForm, signInUrlForRestore };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else window.WcmaDeclarationState = api;
})();
```

`db.php`:
- Add `form_data TEXT,` to the `submissions` CREATE (after `reviewed_at`).
- In `db_insert_submission()`, add `form_data` / `:form_data` to the column and value lists, and add `$data[':form_data'] ??= null;` before `->execute($data)`.
- Add:

```php
/** The calculator inputs of a car's current declaration, for pre-filling a re-declaration. */
function db_get_car_declaration_form(PDO $pdo, int $userId, int $carId): ?array {
    if (db_get_user_car($pdo, $userId, $carId) === null) return null;
    $decl = db_get_car_current_declaration($pdo, $carId);
    if ($decl === null || $decl['form_data'] === null) return null;
    $data = json_decode((string)$decl['form_data'], true);
    return is_array($data) ? $data : null;
}
```

`cars.php`: before the list output, add:

```php
if (($_GET['action'] ?? 'list') === 'declaration') {
    echo json_encode(['success' => true, 'form_data' => db_get_car_declaration_form($pdo, (int)$user['id'], (int)($_GET['car_id'] ?? 0))]);
    exit;
}
```

`car-classing.php`: add the `':form_data'` entry described under Interfaces to the `db_insert_submission()` call.

`js/ui-controller.js` and `js/form-handler.js`: make exactly the changes listed under Interfaces. Do not touch `handleCalculationUpdate`, `updateFormData`, the modifier population functions or the input listeners, beyond `applyFormData` calling them the same way `loadConfiguration` did.

- [ ] **Step 4: Run the tests**

Run: `node --test tests/js/*.test.js` and `php phpunit.phar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add db.php car-classing.php cars.php js/declaration-state.js js/ui-controller.js js/form-handler.js calculator.php tests/js/declaration-state.test.js tests/DeclarationFormDataTest.php
git commit -m "feat(hub): re-declare pre-fills the car's last declaration; visitors keep entries across sign-in"
```

---

### Task 9: Profile page

**Files:**
- Create: `profile.php`, `tests/ProfileTest.php`
- Modify: `db.php` (`db_set_user_password()`)

**Interfaces:**
- Consumes: `db_set_user_name()` (Phase 1), `require_role()`, `renderPageStart()`
- Produces:
  - `db_set_user_password(PDO $pdo, int $id, string $hash): void`
  - `profileValidateName(string $name): array{ok: bool, error: ?string, name: string}`: trims and collapses whitespace; requires 1–100 characters.
  - `profileValidatePassword(string $new, string $confirm): ?string`: returns an error message or null. It requires at least 8 characters and a match, the same rule as registration.

**`profile.php`** (signed in; GET shows it, POST with CSRF updates it):
- **"Your name"** form (`action=name`). The flash reads "Name updated." It also sets `$_SESSION['user_name']`.
- **"Email":** shown read-only, with the note "Contact the club to change your email."
- **"Password"** form (`action=password`): current password (only when the account has one), new password, confirm. It uses `password_verify` against the stored hash, then `password_hash(…, PASSWORD_BCRYPT)`. Google-only accounts see "You sign in with Google." with no form.
- **"Your driver profile"** note: "Your name is also your driver name on tech sheets and gear." with a link to `gear.php`.
- Rendered with `renderPageStart('Profile', '')` / `renderPageEnd()`.

- [ ] **Step 1: Write the failing test**

`tests/ProfileTest.php`:

```php
<?php
// wcma-calculator/tests/ProfileTest.php
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private function lib(): void {
        $src = file_get_contents(__DIR__ . '/../profile.php');
        foreach (['profileValidateName', 'profileValidatePassword'] as $fn) {
            if (function_exists($fn)) continue;
            $start = strpos($src, "function $fn");
            $this->assertNotFalse($start, $fn);
            $end = strpos($src, "\n}\n", $start);
            eval(substr($src, $start, $end - $start + 2));
        }
    }

    public function testNameValidation(): void
    {
        $this->lib();
        $this->assertSame(['ok' => true, 'error' => null, 'name' => 'Jordan Lee'], profileValidateName('  Jordan   Lee '));
        $this->assertFalse(profileValidateName('   ')['ok']);
        $this->assertFalse(profileValidateName(str_repeat('x', 101))['ok']);
    }

    public function testPasswordValidation(): void
    {
        $this->lib();
        $this->assertNull(profileValidatePassword('longenough', 'longenough'));
        $this->assertNotNull(profileValidatePassword('short', 'short'));
        $this->assertNotNull(profileValidatePassword('longenough', 'different1'));
    }

    public function testPasswordUpdateStoresTheHash(): void
    {
        $pdo = make_temp_pdo();
        $u = db_create_user($pdo, ['email' => 'j@example.com', 'name' => 'Jordan Lee', 'password_hash' => password_hash('oldpassword', PASSWORD_BCRYPT), 'google_id' => null]);
        db_set_user_password($pdo, $u, password_hash('newpassword', PASSWORD_BCRYPT));
        $this->assertTrue(password_verify('newpassword', db_find_user_by_id($pdo, $u)['password_hash']));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `php phpunit.phar --filter ProfileTest`. Expected: FAIL.

- [ ] **Step 3: Implement**

Add to `db.php` (Users section):

```php
function db_set_user_password(PDO $pdo, int $id, string $hash): void {
    $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id")->execute([':h' => $hash, ':id' => $id]);
}
```

Write `profile.php`:
- the standard requires (session_bootstrap, db, config, view_helpers)
- the two validators, defined at file scope **before** any output or routing, so the test can extract them
- `$user = require_role('user');`
- POST handling as described under Interfaces (CSRF-checked, PRG redirect back to `profile.php` with a flash)
- the GET render inside `renderPageStart('Profile', '', ['flash' => getFlash()])` … `renderPageEnd()`, using `hub-card` sections and `hub-btn` buttons.

- [ ] **Step 4: Run the tests**

Run: `php phpunit.phar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add profile.php db.php tests/ProfileTest.php
git commit -m "feat(hub): profile page for name and password"
```

---

### Task 10: End-to-end verification

**Files:** none changed unless a defect is found. A defect gets a fix commit with a test.

- [ ] **Step 1: Run the automated suites**

- `php phpunit.phar` → OK
- `node --test tests/js/*.test.js` → all pass
- `for f in *.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done` → no output

- [ ] **Step 2: Reset, seed and serve**

Run `php reset-hub-db.php --confirm`, then `php seed-hub-db.php`, then `php -S localhost:8080`.

- [ ] **Step 3: Browser checks** (Playwright; desktop 1280px and phone 390px)

1. **Signed out, `/`:**
   - The landing hero shows buttons for Class Calculator, Sign in and Create account.
   - The MotorsportReg links are listed.
   - At phone width, the nav collapses to a "Menu" button that opens the list.
2. **Signed out, calculator.** `/car-classing.html?x=1` lands on `/calculator.php?x=1`.
   - Name/Email/Year/Make/Model are visible, and the ratio updates live as weight and HP change.
   - Submitting shows the "Your entries will be kept" message, then goes to sign-in.
   - Signing in as jordan lands on `calculator.php?restore=1` with the weight/HP and modifiers restored.
3. **Signed in as jordan:**
   - Home shows the headline "N things to do before Fall Sprint" (the seed tags nothing, so first the headline is "Which events are you going to?").
   - Tag Fall Sprint with #42 (the S2000): the flash includes "This doesn't register you". The to-do list then shows "Submit a tech sheet for #42" and "Car tech for #42" with "I'll do it at the track".
   - Choosing "I'll do it at the track" moves car tech into "Already done".
4. **Submit tech sheet** from the to-do list: the event is pre-selected. Afterwards, Home shows the sheet as done.
5. **Gear** for Jordan: "Add photos" opens `gear.php?action=pretech&id=…` (the record is created on first use).
6. **Re-declare from Home.** `calculator.php?car={S2000 id}` shows the banner "Declaring class for #42 2004 Honda S2000"; the Name/Year/Make/Model inputs are hidden, and the weight/HP/modifiers are pre-filled from the seed declaration where it has `form_data`. Seed declarations have none, so first submit one declaration through the calculator, then reopen with `?car=` and confirm the pre-fill.
7. **Profile:** changing the name updates the header name. Changing the password with a wrong current password is refused; with the right one it succeeds.
8. **Every older page** (My Cars, My Drivers, a tech sheet, and admin as the admin) shows the hub header, stripe and footer. Nothing overlaps at 390px.
9. **The server log** has no PHP warnings, notices or fatal errors.

- [ ] **Step 4: Report**

Record pass/fail per item, with any error text, in the hand-off. Phase 2 is not complete while any item fails.
