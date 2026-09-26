<?php
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/layout.php';

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

/** The first season link whose label contains $needle (case-insensitive), or null. Links are admin data, not code. */
function seasonLinkMatching(array $links, string $needle): ?array {
    foreach ($links as $link) {
        if (stripos((string)$link['label'], $needle) !== false) return $link;
    }
    return null;
}

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

/**
 * Footer for pages built on renderSiteHeader(): call immediately before </body> so those
 * pages get the same hub footer that renderPageEnd() gives pages built on renderPageStart().
 */
function renderSiteFooter(): void {
    echo hubFooterHtml();
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
