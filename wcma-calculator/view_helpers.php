<?php
require_once __DIR__ . '/roles.php';

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
  <script src="js/feedback.js" defer></script>
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
 * The staff sub-navigation shared by every admin page. $current marks the page being viewed
 * (inert "you are here" text, see navItem()). Inspectors see only the event-day destinations.
 */
function renderAdminNav(string $current, string $role = 'admin'): string {
    $items = [
        'submissions'  => ['admin.php', 'Submissions', 'inspector'],
        'tech-sheets'  => ['admin.php?action=tech-sheets', 'Tech Sheets', 'inspector'],
        'gear'         => ['admin.php?action=gear', 'Gear', 'inspector'],
        'users'        => ['admin.php?action=users', 'Manage Users', 'admin'],
        'events'       => ['admin.php?action=events', 'Events', 'admin'],
        'season-links' => ['admin.php?action=season-links', 'Season Links', 'admin'],
        'settings'     => ['admin.php?action=settings', 'Settings', 'admin'],
        'feedback'     => ['admin.php?action=feedback', 'Feedback', 'admin'],
    ];
    $links = [];
    foreach ($items as $key => [$href, $label, $min]) {
        if (!user_has_role(['role' => $role], $min)) continue;
        $links[] = navItem($href, $label, $key === $current);
    }
    return implode(' ', $links);
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
        $links[] = navItem('gear.php', 'My Drivers', $current === 'gear');
        if (is_inspector()) {
            $links[] = navItem('admin.php', is_admin() ? 'Admin' : 'Inspector', $current === 'admin');
        }
        $links[] = '<a href="auth.php?action=logout">Logout</a>';
    } else {
        $links[] = navItem('auth.php?action=login', 'Sign In', $current === 'auth');
    }

    $links[] = '<a href="#" data-feedback-open>Feedback</a>';

    return implode('', $links);
}

/**
 * My Cars: each of the competitor's cars with its current class declaration and one tech-sheet
 * line per active event (plus any sheet for an event no longer active). Pure data transformation:
 * no DB, no HTML (see tests/AccountCarGroupingTest.php).
 *
 * @param array $cars                Rows from db_get_user_cars()
 * @param array $currentDeclarations car_id => row, from db_get_user_current_declarations()
 * @param array $techSheets          Rows from db_get_user_tech_sheets() (newest first)
 * @param array $activeEvents        Rows from db_get_active_events()
 * @param array $eventNames          [event_id => name] covering inactive events too
 * @return array<int, array{car: array, declaration: ?array, lines: array}>
 */
function buildCarGroups(array $cars, array $currentDeclarations, array $techSheets, array $activeEvents, array $eventNames): array {
    $sheetsByCar = [];
    foreach ($techSheets as $ts) {
        $sheetsByCar[(int)$ts['car_id']][] = $ts;
    }

    $groups = [];
    foreach ($cars as $car) {
        $carId = (int)$car['id'];
        $sheetsByEvent = [];
        foreach ($sheetsByCar[$carId] ?? [] as $ts) {
            $sheetsByEvent[(int)$ts['event_id']] ??= $ts;   // newest sheet for each event
        }

        $lines = [];
        foreach ($activeEvents as $e) {
            $eventId = (int)$e['id'];
            $lines[] = ['event_id' => $eventId, 'event_name' => $e['name'], 'event_date' => $e['event_date'], 'sheet' => $sheetsByEvent[$eventId] ?? null];
            unset($sheetsByEvent[$eventId]);
        }
        foreach ($sheetsByEvent as $eventId => $ts) {
            $lines[] = ['event_id' => $eventId, 'event_name' => $eventNames[$eventId] ?? 'Unknown event', 'event_date' => null, 'sheet' => $ts];
        }

        $groups[] = ['car' => $car, 'declaration' => $currentDeclarations[$carId] ?? null, 'lines' => $lines];
    }
    return $groups;
}
