<?php
// wcma-calculator/admin.php — the Admin back office router (spec §5; admin desktop UX spec 2026-09-29). Each tab's
// handlers and page live in admin-<tab>.php. Admins only. Inspector work (classing, tech sheets, gear) lives in
// inspect.php; old admin.php links to it are redirected by adminMovedActionUrl() (roles.php).
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/admin-feedback.php';
require __DIR__ . '/season-links-lib.php';
require_once __DIR__ . '/clubs-lib.php';
require_once __DIR__ . '/msr-lib.php';
require __DIR__ . '/admin-clubs.php';
require __DIR__ . '/admin-season-links.php';
require __DIR__ . '/admin-ui.php';
require __DIR__ . '/admin-users.php';
require __DIR__ . '/admin-events.php';
require __DIR__ . '/admin-settings.php';
require_once __DIR__ . '/ice-rules.php';

$pdo = db_connect();
db_init($pdo);

$action = $_GET['action'] ?? 'users';
$movedTo = adminMovedActionUrl(is_string($action) ? $action : '', $_GET);
if ($movedTo !== null) { header('Location: ' . $movedTo); exit; }
if ($action === 'login' || $action === 'logout') { header('Location: auth.php?action=' . $action); exit; }
require_role('admin');

/** POST-only and CSRF-checked; otherwise back to $back. */
function adminRequirePost(string $back): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}

$postId = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

switch ($action) {
    case 'user-save':
        adminRequirePost('admin.php?action=users');
        handleUserSave($pdo, $postId);
        break;

    case 'deactivate':
        adminRequirePost('admin.php?action=users');
        handleSetActive($pdo, $postId, false);
        break;

    case 'activate':
        adminRequirePost('admin.php?action=users');
        handleSetActive($pdo, $postId, true);
        break;

    case 'events':
        handleEventsList($pdo);
        break;

    case 'clubs':
        handleClubsList($pdo);
        break;

    case 'club-save':
        adminRequirePost('admin.php?action=clubs');
        handleClubSave($pdo);
        break;

    case 'event-create':
        adminRequirePost('admin.php?action=events');
        handleEventCreate($pdo);
        break;

    case 'event-update':
        adminRequirePost('admin.php?action=events');
        handleEventUpdate($pdo, $postId);
        break;

    case 'event-deactivate':
        adminRequirePost('admin.php?action=events');
        handleEventSetActive($pdo, $postId, false);
        break;

    case 'event-activate':
        adminRequirePost('admin.php?action=events');
        handleEventSetActive($pdo, $postId, true);
        break;

    case 'settings':
        handleSettings($pdo);
        break;

    case 'settings-update':
        adminRequirePost('admin.php?action=settings');
        handleSettingsUpdate($pdo);
        break;

    case 'feedback':
        handleFeedbackList($pdo);
        break;

    case 'feedback-view':
        handleFeedbackView($pdo, is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0);
        break;

    case 'feedback-status':
        adminRequirePost('admin.php?action=feedback');
        handleFeedbackStatus($pdo, $postId);
        break;

    case 'feedback-retry':
        adminRequirePost('admin.php?action=feedback');
        handleFeedbackRetry($pdo, $postId);
        break;

    case 'season-links':
        handleSeasonLinksList($pdo);
        break;

    case 'season-link-save':
        adminRequirePost('admin.php?action=season-links');
        handleSeasonLinkSave($pdo, $postId);
        break;

    case 'season-link-delete':
        adminRequirePost('admin.php?action=season-links');
        handleSeasonLinkDelete($pdo, $postId);
        break;

    default:   // 'users'
        handleUsersList($pdo);
}
