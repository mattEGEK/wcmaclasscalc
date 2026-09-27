<?php
// wcma-calculator/layout.php
//
// The hub page shell: head, header (logo, primary nav, account links), red/black stripe,
// optional sub-navigation, flash message and footer. Callers must have loaded view_helpers.php
// (h()) and roles.php (user_has_role()); current_user() comes from session_bootstrap.php.

/** A local asset URL with its modification time appended, so browsers refetch it after each deploy. */
function hubAsset(string $path): string {
    $mtime = @filemtime(__DIR__ . '/' . $path);
    return h($mtime ? "$path?v=$mtime" : $path);
}

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
        ['key' => 'garage', 'label' => 'Garage', 'href' => 'garage.php'],
        ['key' => 'drivers', 'label' => 'Drivers', 'href' => 'drivers.php'],
        ['key' => 'calculator', 'label' => 'Class Calculator', 'href' => 'calculator.php'],
    ];
    if (user_has_role($user, 'inspector')) {
        $items[] = ['key' => 'inspect', 'label' => 'Inspector', 'href' => 'inspect.php', 'staff' => true];
    }
    if (user_has_role($user, 'admin')) {
        $items[] = ['key' => 'admin', 'label' => 'Admin', 'href' => 'admin.php', 'staff' => true];
    }
    return $items;
}

function hubNavHtml(?array $user, string $section): string {
    $out = '<button type="button" class="hub-menu-btn" aria-expanded="false" aria-controls="hub-nav-list">Menu</button>';
    $out .= '<nav class="hub-nav" aria-label="Main"><ul id="hub-nav-list">';
    foreach (hubNavItems($user) as $item) {
        $cls = !empty($item['staff']) ? ' class="hub-nav-staff"' : '';
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

/** Section tabs (spec §1: "second-level tabs built from the same nav component"). $tabs: key => [href, label]. */
function hubSubnavHtml(string $label, array $tabs, string $current): string {
    $out = '<nav class="hub-tabs" aria-label="' . h($label) . '"><ul>';
    foreach ($tabs as $key => [$href, $text]) {
        $out .= '<li>' . ($key === $current
            ? '<span class="hub-tab-current" aria-current="page">' . h($text) . '</span>'
            : '<a href="' . h($href) . '">' . h($text) . '</a>') . '</li>';
    }
    return $out . '</ul></nav>';
}

const INSPECT_TABS = [
    'roster' => ['inspect.php', 'Event roster'],
    'queue' => ['inspect.php?action=queue', 'Review queue'],
    'classing' => ['inspect.php?action=classing', 'Classing'],
    'gear' => ['inspect.php?action=gear', 'Gear'],
];

const ADMIN_TABS = [
    'users' => ['admin.php?action=users', 'Users & roles'],
    'events' => ['admin.php?action=events', 'Events'],
    'season-links' => ['admin.php?action=season-links', 'Season links'],
    'settings' => ['admin.php?action=settings', 'Settings'],
    'feedback' => ['admin.php?action=feedback', 'Feedback'],
];

function inspectSubnavHtml(string $current): string {
    return hubSubnavHtml('Inspector', INSPECT_TABS, $current);
}

function adminSubnavHtml(string $current): string {
    return hubSubnavHtml('Admin', ADMIN_TABS, $current);
}

/** The signed-in "no access" page (spec §9): a 403 inside the layout. The caller sets the status code. */
function hubRenderForbidden(): void {
    renderPageStart('No access', '');
    $user = current_user();
    if ($user !== null && $user['role'] === 'inspector') {
        echo '<h1 class="hub-page-title">You don&#039;t have access to this page</h1>'
            . '<p>This page is for WCMA admins. Your inspector tools are in the Inspector section.</p>'
            . '<p><a class="hub-btn" href="inspect.php">Go to the Inspector section</a></p>';
    } else {
        echo '<h1 class="hub-page-title">You don&#039;t have access to this page</h1>'
            . '<p>This page is for WCMA inspectors and admins. If you think you should have access, ask a WCMA admin.</p>'
            . '<p><a class="hub-btn" href="index.php">Go to Home</a></p>';
    }
    renderPageEnd();
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
<link rel="stylesheet" href="<?= hubAsset('css/calculator.css') ?>">
<link rel="stylesheet" href="<?= hubAsset('css/hub.css') ?>">
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
