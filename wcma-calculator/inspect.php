<?php
// wcma-calculator/inspect.php — the Inspector section (spec §5): Event roster, Review queue, Classing
// and Gear, for inspectors and admins. Every request is gated here: require_role(inspectActionMinRole())
// (roles.php names the few admin-only actions), and INSPECT_POST_ACTIONS are POST-only and
// CSRF-checked before the router. View models live in inspect-lib.php and markup in inspect-page.php;
// the tech sheet and gear review pages keep their own files (admin-tech-sheets.php, admin-gear.php).
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');

require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';          // feedbackBaseUrl()
require __DIR__ . '/cars-lib.php';
require_once __DIR__ . '/events-lib.php';
require __DIR__ . '/tech-sheet-files.php';
require __DIR__ . '/tech-review-lib.php';
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require_once __DIR__ . '/pretech-lib.php';
require_once __DIR__ . '/pretech-email.php';
require_once __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/tech-sheet-render.php';
require_once __DIR__ . '/garage-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/garage-page.php';
require __DIR__ . '/declaration-review-lib.php';
require __DIR__ . '/inspect-lib.php';
require __DIR__ . '/inspect-page.php';
require __DIR__ . '/declaration-email.php';
require __DIR__ . '/submission-email-render.php';
require __DIR__ . '/admin-tech-sheets.php';
require __DIR__ . '/admin-gear.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    case 'gear':
        handleGearAdminList($pdo);
        break;
    case 'gear-record':
        handleGearAdminView($pdo, $getId);
        break;
    case 'gear-record-accept':
        handleGearAdminAcceptInPerson($pdo, $postId);
        break;
    case 'gear-record-revoke':
        handleGearAdminRevoke($pdo, $postId);
        break;
    case 'gear-photos-accept':
        handleGearAdminPhotosAccept($pdo, $postId);
        break;
    case 'gear-photos-send-back':
        handleGearAdminPhotosSendBack($pdo, $postId);
        break;
    case 'gear-create-accept':
        handleGearCreateAccept($pdo);
        break;
    case 'queue':
        inspectShowQueue($pdo);
        break;
    case 'classing':
        inspectShowClassing($pdo);
        break;
    case 'declaration':
        inspectShowDeclaration($pdo, $getId);
        break;
    case 'declaration-file':
        inspectDeclarationFile($pdo, $getId, is_string($_GET['field'] ?? null) ? $_GET['field'] : '');
        break;
    case 'declarations-export':
        inspectExportDeclarations($pdo);
        break;
    case 'declaration-accept':
        inspectAcceptDeclaration($pdo, $postId);
        break;
    case 'declaration-send-back':
        inspectSendBackDeclaration($pdo, $postId);
        break;
    case 'declaration-resend':
        inspectResendDeclaration($pdo, $postId);
        break;
    case 'declaration-update-contact':
        inspectUpdateDeclarationContact($pdo, $postId);
        break;
    case 'declaration-delete':
        inspectDeleteDeclaration($pdo, $postId);
        break;
    case 'declarations-bulk-delete':
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        inspectBulkDeleteDeclarations($pdo, array_map(fn($v): int => is_scalar($v) ? (int)$v : 0, $ids));
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
    $key = seasonForEvent($event);
    $season = $key['season'];

    $rows = [];
    if ($event !== null) {
        $cars = db_get_event_roster_cars($pdo, $eventId);
        $eventSheets = db_get_event_tech_sheets($pdo, $eventId);
        $rows = inspectRosterRows(
            $cars, $eventSheets, db_get_season_sheets($pdo, $season, $key['discipline']),
            db_get_declarations_for_cars($pdo, array_map(fn(array $c): int => (int)$c['id'], $cars)),
            db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $eventSheets)),
            db_get_self_drivers_for_users($pdo, array_map(fn(array $c): int => (int)$c['owner_user_id'], $cars)),
            db_get_gear_records_for_season($pdo, $season, $key['discipline']),
            $season, $key
        );
    }

    renderPageStart('Event roster', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('roster')]);
    echo renderInspectRosterHtml([
        'events' => $events, 'eventId' => $eventId, 'filter' => $filter,
        'rows' => inspectRosterFilter($rows, $filter), 'counts' => inspectRosterCounts($rows),
        'season' => $season, 'csrf' => generateCsrfToken(), 'discipline' => $key['discipline'],
    ]);
    renderPageEnd(['scripts' => '<script src="js/form-feedback.js"></script>']);
}

function inspectShowQueue(PDO $pdo): void {
    $items = inspectReviewQueue(
        db_get_declarations_awaiting_review($pdo),
        db_get_sheets_awaiting_photo_review($pdo),
        db_get_gear_awaiting_photo_review($pdo)
    );
    renderPageStart('Review queue', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('queue')]);
    echo renderInspectQueueHtml($items);
    renderPageEnd();
}

function inspectShowClassing(PDO $pdo): void {
    $perPage = 50;
    $f = inspectClassingFilters($_GET);
    $result = db_search_declarations($pdo, $f, $perPage, ($f['page'] - 1) * $perPage);
    $pages = max(1, (int)ceil($result['total'] / $perPage));
    if ($f['page'] > $pages) { header('Location: ' . inspectClassingQuery($f, ['page' => $pages])); exit; }

    $isAdmin = is_admin();
    renderPageStart('Classing', 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('classing')]);
    echo renderInspectClassingHtml(['filters' => $f, 'rows' => $result['rows'], 'total' => $result['total'], 'pages' => $pages,
        'isAdmin' => $isAdmin, 'csrf' => generateCsrfToken()]);
    renderPageEnd(['scripts' => '<script src="js/table-tools.js"></script><script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script>'
        . ($isAdmin ? '<script>WcmaTableTools.enableBulkSelect(document.getElementById(\'classing-select-all\'), document.getElementById(\'classing-table\'), document.getElementById(\'bulk-delete-btn\'));</script>' : '')]);
}

function inspectShowDeclaration(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    $car = db_get_car($pdo, (int)$sub['car_id']);
    renderPageStart('Class declaration #' . $id, 'inspect', ['flash' => getFlash(), 'subnav' => inspectSubnavHtml('classing')]);
    echo renderInspectDeclarationHtml([
        'sub' => $sub,
        'car' => $car,
        'owner' => db_find_user_by_id($pdo, (int)$sub['user_id']),
        'reviewer' => !empty($sub['reviewed_by_user_id']) ? db_find_user_by_id($pdo, (int)$sub['reviewed_by_user_id']) : null,
        'history' => $car !== null ? db_get_car_declarations($pdo, (int)$car['id']) : [],
        'isAdmin' => is_admin(),
        'csrf' => generateCsrfToken(),
    ]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script><script src="js/form-feedback.js"></script><script src="js/lightbox.js"></script>']);
}

function inspectBaseUrl(): string {
    return feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', ''));
}

function inspectAcceptDeclaration(PDO $pdo, int $id): void {
    $r = declarationReviewAccept($pdo, $id, (int)current_user()['id']);
    if ($r['ok']) {
        $sent = declarationNotify($pdo, 'accepted', db_get_submission($pdo, $id), inspectBaseUrl(), 'emailSmtpSend');
        setFlash('Declaration accepted.' . ($sent ? ' The competitor was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($r['error'], 'error');
    }
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

function inspectSendBackDeclaration(PDO $pdo, int $id): void {
    $note = is_string($_POST['note'] ?? null) ? $_POST['note'] : '';
    $r = declarationReviewSendBack($pdo, $id, (int)current_user()['id'], $note);
    if ($r['ok']) {
        $sent = declarationNotify($pdo, 'sent_back', db_get_submission($pdo, $id), inspectBaseUrl(), 'emailSmtpSend');
        setFlash('Declaration sent back with your note.' . ($sent ? ' The competitor was emailed.' : ' The email could not be sent.'), $sent ? 'success' : 'error');
    } else {
        setFlash($r['error'], 'error');
    }
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

/** Serves a declaration's uploaded file to an inspector (uploads/ is Deny-from-all). */
function inspectDeclarationFile(PDO $pdo, int $id, string $field): void {
    $columns = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    $sub = isset($columns[$field]) ? db_get_submission($pdo, $id) : null;
    $path = $sub[$columns[$field] ?? ''] ?? null;
    $full = $path ? __DIR__ . '/' . $path : null;
    if ($full === null || !is_file($full)) { http_response_code(404); exit; }

    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
              'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
              'txt' => 'text/plain'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($full, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
}

function inspectCsvSafe($value): string {
    $value = (string)$value;
    return ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) ? "'" . $value : $value;
}

function inspectExportDeclarations(PDO $pdo): void {
    $rows = db_search_declarations($pdo, [], -1, 0)['rows'];   // SQLite: a negative LIMIT means no limit
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="wcma-declarations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Submitted', 'Car #', 'Name', 'Email', 'Year', 'Make', 'Model', 'Weight', 'Declared HP', 'Dyno HP',
        'Base Ratio', 'Weight Factor', 'Modification Factor', 'Modified Ratio', 'Class', 'Review', 'Email Sent']);
    foreach ($rows as $s) {
        fputcsv($out, array_map('inspectCsvSafe', [
            $s['id'], $s['submitted_at'], $s['car_number'] ?? '', $s['name'], $s['email'], $s['year'], $s['make'], $s['model'],
            $s['competition_weight'], $s['declared_hp'], $s['dyno_hp'], $s['base_ratio'], $s['weight_factor'],
            $s['modification_factor'], $s['modified_ratio'], $s['calculated_class'],
            declarationReviewLabel((string)$s['review_status']), $s['email_sent'] ? 'Yes' : 'No',
        ]));
    }
    fclose($out);
    exit;
}

function inspectBuildMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    return $mail;
}

/** Re-sends the declaration email (with its files) to the classing address and to the entrant. */
function inspectResendDeclaration(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }

    $attachments = [];
    foreach (['dyno_chart_path', 'dyno_table_path', 'car_image_path'] as $col) {
        if ($sub[$col] && is_file(__DIR__ . '/' . $sub[$col])) $attachments[] = __DIR__ . '/' . $sub[$col];
    }
    $recipients = [
        [(string)db_get_setting($pdo, 'classing_recipient_email', config_default('CLASSING_RECIPIENT_EMAIL', 'classing@wcma.ca')),
         (string)db_get_setting($pdo, 'classing_recipient_name', config_default('CLASSING_RECIPIENT_NAME', 'WCMA Classing')),
         'WCMA Classing Calculator Submission — ' . $sub['name'] . ' — ' . date('M j, Y', strtotime((string)$sub['submitted_at']))],
        [(string)$sub['email'], (string)$sub['name'], 'Your WCMA Classing Calculator Submission'],
    ];
    $sent = false;
    try {
        foreach ($recipients as $i => [$address, $name, $subject]) {
            $mail = inspectBuildMailer();
            $mail->addAddress($address, $name);
            if ($i === 0) $mail->addReplyTo((string)$sub['email'], (string)$sub['name']);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = renderSubmissionEmailHtml($sub, emailLogoSrc($mail), true);
            $mail->AltBody = renderSubmissionEmailText($sub, true);
            foreach ($attachments as $path) $mail->addAttachment($path, basename($path));
            $mail->send();
        }
        $sent = true;
    } catch (Exception $e) {
        error_log('Declaration resend error: ' . $e->getMessage());
    }
    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Declaration email re-sent.' : 'The declaration email could not be sent. Check the server log.', $sent ? 'success' : 'error');
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

function inspectUpdateDeclarationContact(PDO $pdo, int $id): void {
    if (db_get_submission($pdo, $id) === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    $field = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $name = $field('name');
    $email = $field('email');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('Name and a valid email are required.', 'error');
    } else {
        $comments = $field('comments');
        db_update_submission_contact($pdo, $id, ['name' => $name, 'email' => $email, 'year' => $field('year'), 'make' => $field('make'),
            'model' => $field('model'), 'comments' => $comments !== '' ? $comments : null]);
        setFlash('Contact details updated.', 'success');
    }
    header('Location: inspect.php?action=declaration&id=' . $id);
    exit;
}

function inspectDeleteDeclarationFiles(int $id): void {
    $dir = __DIR__ . '/uploads/' . $id;
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}

function inspectDeleteDeclaration(PDO $pdo, int $id): void {
    $sub = db_get_submission($pdo, $id);
    if ($sub === null) { setFlash('Class declaration not found.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    if (db_count_tech_sheets_for_submission($pdo, $id) > 0) {
        setFlash('This declaration is on a submitted tech sheet, so it cannot be deleted.', 'error');
        header('Location: inspect.php?action=declaration&id=' . $id);
        exit;
    }
    inspectDeleteDeclarationFiles($id);
    db_delete_submission($pdo, $id);
    db_restore_current_declaration($pdo, (int)$sub['car_id']);
    setFlash('Declaration deleted.', 'success');
    header('Location: inspect.php?action=classing');
    exit;
}

function inspectBulkDeleteDeclarations(PDO $pdo, array $ids): void {
    $ids = array_values(array_unique(array_filter($ids, fn(int $id): bool => $id > 0)));
    if (!$ids) { setFlash('No declarations selected.', 'error'); header('Location: inspect.php?action=classing'); exit; }
    $deleted = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        $sub = db_get_submission($pdo, $id);
        if ($sub === null) continue;
        if (db_count_tech_sheets_for_submission($pdo, $id) > 0) { $skipped++; continue; }
        inspectDeleteDeclarationFiles($id);
        db_delete_submission($pdo, $id);
        db_restore_current_declaration($pdo, (int)$sub['car_id']);
        $deleted++;
    }
    setFlash("Deleted {$deleted} declaration(s)." . ($skipped > 0 ? " {$skipped} skipped (on a submitted tech sheet)." : ''), 'success');
    header('Location: inspect.php?action=classing');
    exit;
}
