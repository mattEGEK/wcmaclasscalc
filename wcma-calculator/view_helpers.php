<?php
function generateCsrfToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $message, string $type): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function renderSiteHeader(string $title, string $navHtml = ''): void {
    ?>
<header class="page-header">
  <div class="header-content">
    <a href="car-classing.html" class="logo-home-link">
      <img src="https://www.wcma.ca/wp-content/uploads/WCMA-Logo.png" alt="WCMA Logo" class="wcma-logo-sm">
    </a>
    <h1><?= h($title) ?></h1>
  </div>
  <nav><?= $navHtml ?></nav>
</header>
<?php
}

/**
 * A single nav destination: a link, or (when $isCurrent) inert "you are
 * here" text rendered in the same position, so the set of destinations
 * stays identical across every page.
 */
function navItem(string $href, string $label, bool $isCurrent): string {
    if ($isCurrent) {
        return '<span class="nav-current" aria-current="page">' . h($label) . '</span>';
    }
    return '<a href="' . h($href) . '">' . h($label) . '</a>';
}

/**
 * Common cross-page nav links (Calculator / My Cars / Admin / Logout, or
 * Sign In for guests), used alongside each page's own back-link/actions.
 * $current marks which destination is the page already being viewed.
 */
function renderCommonNav(string $current = ''): string {
    $user = current_user();
    $links = [];

    $links[] = navItem('car-classing.html', 'Calculator', $current === 'calculator');

    if ($user !== null) {
        $links[] = navItem('account.php', 'My Cars', $current === 'account');
        if (is_admin()) {
            $links[] = navItem('admin.php', 'Admin', $current === 'admin');
        }
        $links[] = '<a href="auth.php?action=logout">Logout</a>';
    } else {
        $links[] = navItem('auth.php?action=login', 'Sign In', $current === 'auth');
    }

    return implode('', $links);
}

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
