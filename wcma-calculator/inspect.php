<?php
// wcma-calculator/inspect.php — the Inspector section (spec §5): Event roster, Review queue, Classing
// and Gear, for inspectors and admins. Every request is gated here: require_role(inspectActionMinRole())
// (roles.php names the few admin-only actions), and INSPECT_POST_ACTIONS are POST-only and
// CSRF-checked before the router. View models live in inspect-lib.php and markup in inspect-page.php;
// the tech sheet and gear review pages keep their own files (admin-tech-sheets.php, admin-gear.php).
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';          // feedbackBaseUrl()
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/tech-sheet-files.php';
require __DIR__ . '/tech-review-lib.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/tech-sheet-render.php';
require __DIR__ . '/garage-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/garage-page.php';
require __DIR__ . '/declaration-review-lib.php';
require __DIR__ . '/inspect-lib.php';
require __DIR__ . '/inspect-page.php';
require __DIR__ . '/admin-tech-sheets.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';

$pdo = db_connect();
db_init($pdo);

define('TECH_EMAIL', db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')));
define('TECH_NAME',  db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')));

$action = is_string($_GET['action'] ?? null) && $_GET['action'] !== '' ? $_GET['action'] : 'roster';
require_role(inspectActionMinRole($action));
if (in_array($action, INSPECT_POST_ACTIONS, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: inspect.php'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}
$getId = is_scalar($_GET['id'] ?? null) ? (int)$_GET['id'] : 0;
$postId = is_scalar($_POST['id'] ?? null) ? (int)$_POST['id'] : 0;

switch ($action) {
    case 'tech-sheet':
        handleTechSheetView($pdo, $getId);
        break;
    case 'tech-sheet-sig':
        handleTechSheetSig($pdo, $getId, is_string($_GET['which'] ?? null) ? $_GET['which'] : '');
        break;
    case 'tech-sheet-accept':
        handleTechSheetAccept($pdo, $postId);
        break;
    case 'tech-sheet-revoke':
        handleTechSheetRevoke($pdo, $postId);
        break;
    case 'tech-sheet-photos-accept':
        handleTechSheetPhotosAccept($pdo, $postId);
        break;
    case 'tech-sheet-photos-send-back':
        handleTechSheetPhotosSendBack($pdo, $postId);
        break;
    default:
        inspectShowRoster($pdo);
}

function inspectShowRoster(PDO $pdo): void {
    $events = db_get_all_events($pdo);
    $eventId = is_scalar($_GET['event'] ?? null) ? (int)$_GET['event'] : 0;
    if (!in_array($eventId, array_map(fn(array $e): int => (int)$e['id'], $events), true)) {
        $eventId = techDefaultEventId($events, date('Y-m-d'));
    }
    $filter = is_string($_GET['filter'] ?? null) && isset(INSPECT_ROSTER_FILTERS[$_GET['filter']]) ? $_GET['filter'] : 'all';
    $event = $eventId > 0 ? db_get_event($pdo, $eventId) : null;
    $season = techSeasonFromDate($event['event_date'] ?? null);

    $rows = [];
    if ($event !== null) {
        $cars = db_get_event_roster_cars($pdo, $eventId);
        $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
        $rows = inspectRosterRows(
            $cars, $eventSheets, db_get_season_sheets($pdo, $season),
            db_get_declarations_for_cars($pdo, array_map(fn(array $c): int => (int)$c['id'], $cars)),
            db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $eventSheets)),
            db_get_self_drivers_for_users($pdo, array_map(fn(array $c): int => (int)$c['owner_user_id'], $cars)),
            db_get_gear_records_for_season($pdo, $season),
            $season
        );
    }

    renderPageStart('Event roster', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('roster')]);
    echo renderInspectRosterHtml([
        'events' => $events, 'eventId' => $eventId, 'filter' => $filter,
        'rows' => inspectRosterFilter($rows, $filter), 'counts' => inspectRosterCounts($rows),
        'season' => $season, 'csrf' => generateCsrfToken(),
    ]);
    renderPageEnd(['scripts' => '<script src="js/form-feedback.js"></script>']);
}
