<?php
// wcma-calculator/garage.php — the competitor's Garage (spec §4): the car list, Add a car, the car
// page, and class declarations. Views live in garage-page.php; view models in garage-lib.php.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require_once __DIR__ . '/events-lib.php';
require_once __DIR__ . '/reminders-lib.php';
require_once __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
require_once __DIR__ . '/garage-lib.php';
require_once __DIR__ . '/ice-sheet-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/garage-page.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    handleGaragePost($pdo, $uid, (string)($_POST['action'] ?? ''));
    exit;
}

if (($_GET['action'] ?? '') === 'add') {
    $event = garageAddEvent(isset($_GET['event_id']) ? db_get_event($pdo, (int)$_GET['event_id']) : null, date('Y-m-d'));
    $values = $event !== null ? ['disciplines' => ($event['discipline'] ?? 'summer') === 'ice' ? 'ice' : 'summer'] : [];
    garageRenderAdd($pdo, $values, null, $event);
    exit;
}
if (isset($_GET['declaration'])) {
    garageShowDeclaration($pdo, $uid, (int)$_GET['declaration']);
    exit;
}
if (($_GET['action'] ?? '') === 'file') {
    garageDeclarationFile($pdo, $uid, (int)($_GET['id'] ?? 0), (string)($_GET['field'] ?? ''));
    exit;
}
if (isset($_GET['car'])) {
    garageShowCar($pdo, $uid, (int)$_GET['car']);
    exit;
}
garageShowList($pdo, $uid);

function garageShowList(PDO $pdo, int $uid): void {
    $all = db_get_user_cars($pdo, $uid, true);
    $active = array_values(array_filter($all, fn(array $c): bool => $c['archived_at'] === null));
    $archived = array_values(array_filter($all, fn(array $c): bool => $c['archived_at'] !== null));
    $sheets = db_get_user_tech_sheets($pdo, $uid);
    $tagged = [];
    $formats = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        $tagged[(int)$p['car_id']][] = (int)$p['event_id'];
        $formats[(int)$p['car_id']][(int)$p['event_id']] = (string)$p['formats'];
    }
    $events = db_get_active_events($pdo);

    $cards = [];
    foreach ($active as $car) {
        $cid = (int)$car['id'];
        $carSheets = array_values(array_filter($sheets, fn(array $s): bool => (int)$s['car_id'] === $cid));
        $cards[] = garageCard($car, db_get_car_declarations($pdo, $cid), $carSheets, $tagged[$cid] ?? [], $events, gearSeasonNow(), date('Y-m-d'), gearSeasonNow(DISCIPLINE_ICE), $formats[$cid] ?? []);
    }

    // js/ui-controller.js fetches account.php (which redirects here) and scrapes this meta tag for
    // the calculator's draft actions, so it must be on the list page for every signed-in user.
    $csrf = generateCsrfToken();
    renderPageStart('Garage', 'garage', ['flash' => getFlash(), 'extraHead' => '<meta name="csrf-token" content="' . h($csrf) . '">']);
    echo renderGarageListHtml(['cards' => $cards, 'archived' => $archived, 'csrf' => $csrf]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function garageRenderAdd(PDO $pdo, array $values, ?string $error, ?array $event = null): void {
    renderPageStart('Add a car', 'garage', [
        'subnav' => '<a href="garage.php">&larr; Back to Garage</a>',
        'flash' => $error === null ? getFlash() : null,
    ]);
    echo renderAddCarHtml([
        'csrf' => generateCsrfToken(), 'values' => $values, 'error' => $error, 'event' => $event,
        'msrLink' => seasonLinkMatching(db_get_season_links($pdo, true), 'Classing'),
    ]);
    renderPageEnd();
}

function garageShowCar(PDO $pdo, int $uid, int $carId, ?array $detailsForm = null): void {
    $car = db_get_user_car($pdo, $uid, $carId);
    if ($car === null) { setFlash('Car not found.', 'error'); header('Location: garage.php'); exit; }

    $season = gearSeasonNow();
    $allSheets = array_values(array_filter(db_get_user_tech_sheets($pdo, $uid), fn(array $s): bool => (int)$s['car_id'] === $carId));
    $sheets = garageSummerSheets($allSheets);
    $tagged = [];
    $formatsByEvent = [];
    foreach (db_get_user_event_plans($pdo, $uid) as $p) {
        if ((int)$p['car_id'] !== $carId) continue;
        $tagged[] = (int)$p['event_id'];
        $formatsByEvent[(int)$p['event_id']] = (string)$p['formats'];
    }
    $eventNames = [];
    foreach (db_get_all_events($pdo) as $e) {
        $eventNames[(int)$e['id']] = (string)$e['name'];
    }
    $today = date('Y-m-d');
    $events = garageCarEvents($allSheets, $tagged, db_get_active_events($pdo), $eventNames, $today, $formatsByEvent);
    foreach ($events['tagged'] as $i => $row) {
        $entryRow = db_get_entry($pdo, $uid, (int)$row['event']['id'], $carId);
        $events['tagged'][$i]['suppsAckAt'] = $entryRow['supps_ack_at'] ?? null;
        $events['tagged'][$i]['driverIds'] = $entryRow !== null ? db_get_entry_driver_ids($pdo, (int)$entryRow['id']) : [];
    }

    $ownerGear = db_get_user_gear_records($pdo, $uid);
    $driversBySheet = db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $allSheets));
    foreach ($events['tagged'] as $i => $row) {
        $events['tagged'][$i]['gearLinks'] = $row['sheet'] !== null
            ? gearLinksForSheet($row['sheet'], $driversBySheet[(int)$row['sheet']['id']] ?? [], $ownerGear)
            : [];
    }

    $seasonSheets = array_values(array_filter(garageRaceSheets($sheets), fn(array $s): bool => (int)$s['season'] === $season));
    $status = techCarStatus($seasonSheets);
    $declarations = db_get_car_declarations($pdo, $carId);

    $taggedSummer = $taggedIce = false;
    foreach ($events['tagged'] as $row) {
        if ((($row['event']['discipline'] ?? 'summer') === 'ice')) $taggedIce = true; else $taggedSummer = true;
    }
    $seasons = garageCarSeasons($car, $declarations, $allSheets, $taggedSummer, $taggedIce);
    $events['untagged'] = garageAddableEvents($events['untagged'], $car, $seasons);

    renderPageStart(carDisplayName($car), 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php">&larr; Back to Garage</a>']);
    echo renderGarageCarHtml([
        'car' => $car, 'class' => garageClassLine($declarations), 'declarations' => $declarations,
        'season' => $season, 'techState' => $status['state'], 'techLabel' => techCarStatusLabel($status, $season),
        'techAction' => garageTechPhotosAction($seasonSheets, $status),
        'events' => $events, 'csrf' => generateCsrfToken(), 'detailsForm' => $detailsForm,
        'offerReminders' => remindersShouldOffer(db_find_user_by_id($pdo, $uid)),
        'seasons' => $seasons,
        'iceSheets' => array_values(array_filter($allSheets, fn(array $s): bool => techSheetIsIce($s))),
        'usesSummer' => $seasons['summer'],
        'usesRace' => $seasons['summer'] && garageCarRaces($car, $declarations, $allSheets, garageEntryTiers($formatsByEvent, db_get_active_events($pdo), $today)['race']),
        'ice' => garageIceSummary($allSheets, $taggedIce, gearSeasonNow(DISCIPLINE_ICE), isset($car['disciplines']) ? (string)$car['disciplines'] : null),
        'taDrift' => garageTaDriftSummaries($carId, $allSheets, garageEntryTiers($formatsByEvent, db_get_active_events($pdo), $today)['taDriftClubs'], $season),
        'revokeNotes' => garageRevokeNotes($allSheets),
        'tagDefaults' => eventsDefaultFormats($pdo, $car, ['id' => 0, 'discipline' => 'summer', 'host_club' => 'any']),
        'coDrivers' => db_get_car_drivers($pdo, $carId),
        'carDriverChoices' => eventsCarDriverChoices($pdo, $uid, $carId),
        'coDriverOptions' => array_values(array_filter(db_get_user_drivers($pdo, $uid), fn(array $d): bool =>
            (int)($d['user_id'] ?? 0) !== $uid && !in_array((int)$d['id'], array_map(fn(array $c): int => (int)$c['id'], db_get_car_drivers($pdo, $carId)), true))),
    ]);
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function handleGaragePost(PDO $pdo, int $uid, string $action): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    switch ($action) {
        case 'add':
            $event = garageAddEvent(isset($_POST['event_id']) ? db_get_event($pdo, (int)$_POST['event_id']) : null, date('Y-m-d'));
            $v = carsValidateDetails($_POST, true);
            if (!$v['ok']) { garageRenderAdd($pdo, $v['data'], $v['error'], $event); return; }
            $id = db_create_car($pdo, $uid, $v['data']);
            if ($event !== null && !eventsTagCar($pdo, $uid, (int)$event['id'], $id)['ok']) $event = null;
            $next = garageAfterAdd($id, (string)$v['data']['disciplines'], $event);
            setFlash($next['flash'], 'success');
            header('Location: ' . $next['url']);
            return;
        case 'archive':
            $ok = db_archive_car($pdo, $uid, $carId);
            setFlash($ok ? 'Car archived. Its history is kept.' : 'Car not found.', $ok ? 'success' : 'error');
            header('Location: garage.php');
            return;
        case 'restore':
            $ok = db_restore_car($pdo, $uid, $carId);
            setFlash($ok ? 'Car restored.' : 'Car not found.', $ok ? 'success' : 'error');
            header('Location: garage.php' . ($ok ? '?car=' . $carId : ''));
            return;
        case 'update-car':
            if (db_get_user_car($pdo, $uid, $carId) === null) break;
            $v = carsValidateDetails($_POST);
            if (!$v['ok']) { garageShowCar($pdo, $uid, $carId, ['values' => $v['data'], 'error' => $v['error']]); return; }
            db_update_car($pdo, $carId, $v['data']);
            setFlash('Car details saved.', 'success');
            header('Location: garage.php?car=' . $carId);
            return;
        case 'tag':
            $eventId = (int)($_POST['event_id'] ?? 0);
            $r = eventsTagCar($pdo, $uid, $eventId, $carId, entryFormatsFromPost($_POST), !empty($_POST['supps_ack']));
            $extra = $r['ok'] ? remindersRecordTagChoice($pdo, $uid, $_POST) : '';
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING . $extra : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            $event = $r['ok'] ? db_get_event($pdo, $eventId) : null;
            header('Location: ' . ($event !== null ? garageAfterTagUrl($carId, $event, ($_POST['then'] ?? '') === 'sheet') : 'garage.php?car=' . $carId));
            return;
        case 'formats':
            $r = eventsSetFormats($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId, entryFormatsFromPost($_POST) ?? [], !empty($_POST['supps_ack']), entryDriversFromPost($_POST));
            setFlash($r['ok'] ? 'Saved what this car is running.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId . '#events');
            return;
        case 'untag':
            $r = eventsUntagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), $carId);
            setFlash($r['ok'] ? 'Removed from your events.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId);
            return;
        case 'add-co-driver':
            $r = eventsAddCoDriver($pdo, $uid, $carId, $_POST);
            setFlash($r['ok'] ? 'Co-driver added.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId . '#co-drivers');
            return;
        case 'remove-co-driver':
            $r = eventsRemoveCoDriver($pdo, $uid, $carId, (int)($_POST['driver_id'] ?? 0));
            setFlash($r['ok'] ? 'Removed from this car.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            header('Location: garage.php?car=' . $carId . '#co-drivers');
            return;
        case 'resend-declaration':
            garageResendDeclaration($pdo, $uid, (int)($_POST['id'] ?? 0));
            return;
        case 'delete-declaration':
            garageDeleteDeclaration($pdo, $uid, (int)($_POST['id'] ?? 0));
            return;
    }
    setFlash('Car not found.', 'error');
    header('Location: garage.php');
}

function garageShowDeclaration(PDO $pdo, int $uid, int $id): void {
    $sub = db_get_user_submission($pdo, $uid, $id);
    if (!$sub) { setFlash('Class declaration not found.', 'error'); header('Location: garage.php'); exit; }
    renderPageStart('Class declaration', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php?car=' . (int)$sub['car_id'] . '">&larr; Back to the car</a>']);
    echo renderDeclarationHtml($sub, generateCsrfToken());
    renderPageEnd(['scripts' => '<script src="js/confirm-modal.js"></script>']);
}

function buildGarageMailer(): PHPMailer {
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

function garageResendDeclaration(PDO $pdo, int $uid, int $id): void {
    $sub = db_get_user_submission($pdo, $uid, $id);
    if (!$sub) { setFlash('Class declaration not found.', 'error'); header('Location: garage.php'); exit; }

    $attachments = [];
    foreach (['dyno_chart_path', 'dyno_table_path', 'car_image_path'] as $col) {
        if ($sub[$col]) {
            $path = __DIR__ . '/' . $sub[$col];
            if (file_exists($path)) $attachments[] = ['path' => $path, 'name' => basename($path)];
        }
    }

    $sent = false;
    try {
        $mail = buildGarageMailer();
        $mail->addAddress($sub['email'], $sub['name']);
        $mail->Subject = 'Your WCMA Class Declaration';
        $mail->isHTML(true);
        $mail->Body = '<p>Class: <strong>' . htmlspecialchars($sub['calculated_class'] ?? '') . '</strong></p><p>Vehicle: ' . htmlspecialchars(trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model'])) . '</p>';
        $mail->AltBody = 'Class: ' . ($sub['calculated_class'] ?? '') . "\nVehicle: " . trim($sub['year'] . ' ' . $sub['make'] . ' ' . $sub['model']);
        foreach ($attachments as $att) $mail->addAttachment($att['path'], $att['name']);
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Garage resend error: ' . $e->getMessage());
    }

    db_update_email_sent($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Confirmation re-sent to your email.' : 'Failed to send email. Please try again later.', $sent ? 'success' : 'error');
    header('Location: garage.php?declaration=' . $id);
    exit;
}

function garageDeleteDeclaration(PDO $pdo, int $uid, int $id): void {
    $sub = db_get_user_submission($pdo, $uid, $id);
    if (!$sub) { setFlash('Class declaration not found.', 'error'); header('Location: garage.php'); exit; }
    if (db_count_tech_sheets_for_submission($pdo, $id) > 0) {
        setFlash('This declaration is on a submitted tech sheet, so it cannot be deleted.', 'error');
        header('Location: garage.php?declaration=' . $id);
        exit;
    }
    $upload_dir = __DIR__ . '/uploads/' . $id;
    if (is_dir($upload_dir)) {
        foreach (glob($upload_dir . '/*') as $file) unlink($file);
        rmdir($upload_dir);
    }
    db_delete_submission($pdo, $id);
    db_restore_current_declaration($pdo, (int)$sub['car_id']);
    setFlash('Class declaration deleted.', 'success');
    header('Location: garage.php?car=' . (int)$sub['car_id']);
    exit;
}

function garageDeclarationFile(PDO $pdo, int $uid, int $id, string $field): void {
    $field_map = ['dyno_chart' => 'dyno_chart_path', 'dyno_table' => 'dyno_table_path', 'car_image' => 'car_image_path'];
    if (!isset($field_map[$field])) { http_response_code(404); exit; }

    $sub = db_get_user_submission($pdo, $uid, $id);
    $db_field = $field_map[$field];
    if (!$sub || !$sub[$db_field]) { http_response_code(404); exit; }

    $path = __DIR__ . '/' . $sub[$db_field];
    if (!file_exists($path)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'txt' => 'text/plain'];

    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
