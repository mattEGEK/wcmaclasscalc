<?php
// wcma-calculator/tech-sheets.php
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/tech-sheet-data.php';
require __DIR__ . '/tech-sheet-render.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/tech-sheet-files.php';
require __DIR__ . '/feedback-lib.php';
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require_once __DIR__ . '/pretech-lib.php';
require_once __DIR__ . '/pretech-email.php';
require __DIR__ . '/pretech-page.php';
require_once __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-chips.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/garage-page.php';
require_once __DIR__ . '/events-lib.php';
require __DIR__ . '/ice-sheet-page.php';
require __DIR__ . '/ta-drift-sheet-page.php';
require_once __DIR__ . '/tech-sheet-next.php';
require_once __DIR__ . '/clubs-lib.php';
require_once __DIR__ . '/email-copy.php';
require_once __DIR__ . '/revoke-lib.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

define('TECH_SHEET_EMAIL', db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')));
define('TECH_SHEET_EMAIL_NAME', db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')));

/**
 * Signature-src resolver for the web/print view: an authenticated URL served
 * through the ?action=sig route below (uploads/ itself is Deny-from-all).
 * Reusable by other scripts (e.g. a future admin/review page) via $script.
 */
function techSheetSignatureResolverWeb(int $techSheetId, string $script = 'tech-sheets.php'): callable {
    return function (string $which, string $path) use ($techSheetId, $script): ?string {
        return $script . '?action=sig&id=' . $techSheetId . '&which=' . rawurlencode($which);
    };
}

/**
 * Signature-src resolver for email bodies: mail recipients have no session
 * (can't use the authenticated URL), and data: URIs don't work here either —
 * Gmail and other major webmail clients strip embedded base64 images from
 * HTML mail, which is what made signatures show up as broken links. Instead
 * the PNG is attached to the given PHPMailer instance as a CID-embedded
 * image, which every major client renders inline.
 */
function techSheetSignatureResolverEmail(PHPMailer $mail): callable {
    return function (string $which, string $path) use ($mail): ?string {
        $full = __DIR__ . '/' . $path;
        if (!is_file($full)) return null;
        $cid = 'sig-' . $which;
        try {
            $mail->addEmbeddedImage($full, $cid, basename($full), 'base64', 'image/png');
        } catch (Exception $e) {
            return null;
        }
        return 'cid:' . $cid;
    };
}

function requireTechSheetLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

$action = $_GET['action'] ?? 'new';

switch ($action) {
    case 'new':
        $user = requireTechSheetLogin();
        handleNew($pdo, $user, (int)($_GET['car_id'] ?? 0), (int)($_GET['event_id'] ?? 0));
        break;

    case 'submit':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: tech-sheets.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmit($pdo, $user);
        break;

    case 'new-ice':
        $user = requireTechSheetLogin();
        handleNewIce($pdo, $user, (int)($_GET['car_id'] ?? 0), (int)($_GET['event_id'] ?? 0));
        break;

    case 'submit-ice':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmitIce($pdo, $user);
        break;

    case 'new-ta-drift':
        $user = requireTechSheetLogin();
        handleNewTaDrift($pdo, $user, (int)($_GET['car_id'] ?? 0), (int)($_GET['event_id'] ?? 0));
        break;

    case 'submit-ta-drift':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmitTaDrift($pdo, $user);
        break;

    case 'view':
        $user = requireTechSheetLogin();
        handleView($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'edit':
        $user = requireTechSheetLogin();
        handleEdit($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'update':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleUpdate($pdo, $user);
        break;

    case 'resend':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleResendTechSheet($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'pretech':
        $user = requireTechSheetLogin();
        handlePretech($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'pretech-submit':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: garage.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handlePretechSubmit($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'sig':
        $user = requireTechSheetLogin();
        handleTechSheetSignature($pdo, $user, (int)($_GET['id'] ?? 0), (string)($_GET['which'] ?? ''));
        break;

    default:
        header('Location: garage.php');
        exit;
}

function handleNew(PDO $pdo, array $user, int $carId, int $eventId = 0): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        $cars = db_get_user_cars($pdo, (int)$user['id']);
        if (!$cars) {
            setFlash('Add your car before submitting a tech sheet.', 'error');
            header('Location: garage.php?action=add');
            exit;
        }
        renderPageStart('Submit a tech sheet', 'garage', ['flash' => getFlash()]);
        echo renderTechSheetCarPickerHtml($cars, $eventId);
        renderPageEnd();
        return;
    }
    $declaration = db_get_car_current_declaration($pdo, $carId);
    if (!$declaration && ($car['disciplines'] ?? null) === 'ta_drift') {
        // A TA/Drift-only car has no class declaration: it takes the TA/Drift sheet.
        header('Location: tech-sheets.php?action=new-ta-drift&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : ''));
        exit;
    }
    if (!$declaration) {
        setFlash('Declare a class for this car before submitting a tech sheet.', 'error');
        header('Location: calculator.php?car=' . $carId);
        exit;
    }

    $events = db_get_active_events($pdo, DISCIPLINE_SUMMER);
    if (empty($events)) {
        setFlash('There are no upcoming events open for tech sheet submission yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $pickers = eventsSheetDriverRows($pdo, (int)$user['id'], $carId);
    $byId = [];
    foreach ($pickers as $d) $byId[(int)$d['id']] = $d;
    $prefill = eventsSheetPrefill($pdo, (int)$user['id'], $carId, $eventId);
    $type = ($_GET['type'] ?? '') === 'endurance' ? 'endurance' : 'standard';
    renderTechSheetForm($declaration, $events, generateCsrfToken(), null, [], $pickers, $car, $eventId, [
        'driver1' => $prefill['driver1'],
        'rows' => techSheetPrefillRows($byId, $prefill['others']),
        'sheetType' => $type,
        'notice' => $type === 'standard' && $prefill['count'] > 1
            ? 'tech-sheets.php?action=new&car_id=' . $carId . '&event_id=' . $eventId . '&type=endurance' : null,
    ]);
}

function handleView(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $ownerSheets = db_get_user_tech_sheets($pdo, (int)$user['id']);
    $carStatus = techSheetIsTaDrift($sheet) ? taDriftSheetCarStatus($sheet, $ownerSheets) : techCarStatusForSheet($sheet, $ownerSheets);
    $statusLabel = techSheetIsTaDrift($sheet)
        ? taDriftCarTechStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)$sheet['club'])
        : techCarStatusLabel($carStatus, (int)($sheet['season'] ?? date('Y')), (string)($sheet['discipline'] ?? 'summer'));
    $gearLinks = gearLinksForSheet($sheet, $drivers, db_get_user_gear_records($pdo, (int)$user['id']));
    $csrf = generateCsrfToken();
    $flash = getFlash();
    $title = techSheetViewTitle($sheet, $event, db_get_car($pdo, (int)$sheet['car_id']));
    $club = clubForEvent($event !== null && !empty($event['host_club']) ? db_get_club($pdo, (string)$event['host_club']) : null, $event);
    $chips = $gearLinks ? renderGearChips($gearLinks, 'owner', ['sheet_season' => (int)($sheet['season'] ?? 0), 'sheet_id' => (int)$sheet['id']]) : '';
    renderPageStart($title, 'garage', ['flash' => $flash, 'subnav' => '<a href="garage.php?car=' . (int)$sheet['car_id'] . '">&larr; Back to the car</a>']);
    ?>
  <h1 class="hub-page-title"><?= h($title) ?></h1>
  <?= revokeNoticeHtml($sheet['revoke_note'] ?? null, 'Tech') ?>
  <p class="no-print">Car status: <span class="hub-status <?= h(homeStatusClass($carStatus['state'])) ?>"><?= h($statusLabel) ?></span></p>
  <?= renderTechSheetNextStepsHtml($sheet, $event, $carStatus, $chips, $club) ?>
  <div class="sheet-actions no-print">
    <?php if (pretechSheetEditable($sheet)): ?>
    <a href="tech-sheets.php?action=edit&id=<?= (int)$sheet['id'] ?>" class="hub-btn hub-btn--secondary">Edit</a>
    <?php endif; ?>
    <button type="button" class="hub-btn hub-btn--secondary" onclick="window.print()">Print</button>
    <form method="post" action="tech-sheets.php?action=resend" class="garage-inline-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$sheet['id'] ?>">
      <button type="submit" class="hub-btn hub-btn--secondary">Resend email</button>
    </form>
  </div>
  <div class="sheet-doc hub-card"><?= renderTechSheetHtml($sheet, $drivers, $event ?? [], techSheetSignatureResolverWeb((int)$sheet['id']), 'assets/wcma-logo.png') ?></div>
<script>try { localStorage.removeItem(<?= json_encode(techSheetDraftKey((int)$sheet['user_id'], (int)$sheet['car_id'], (int)$sheet['event_id'])) ?>); } catch (e) {}</script>
<?php
    renderPageEnd(['scripts' => '<script src="js/form-feedback.js"></script>']);
}

function handlePretech(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $identity = db_get_sheet_identity_sheets($pdo, $sheet);
    renderPretechPage($sheet, $event, pretechPageMode($sheet, $identity), pretechSnapshot($pdo, $id), generateCsrfToken(), getFlash());
}

function handlePretechSubmit(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: garage.php');
        exit;
    }

    $result = pretechSubmit($pdo, $id);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
        $sent = pretechNotify(
            $pdo, 'submitted', db_get_tech_sheet($pdo, $id), $event,
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => TECH_SHEET_EMAIL, 'name' => TECH_SHEET_EMAIL_NAME], 'emailSmtpSend'
        );
        setFlash('Photos submitted for review.' . ($sent ? ' We emailed you a confirmation.' : ' The confirmation email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: tech-sheets.php?action=pretech&id=' . $id);
    exit;
}

function handleEdit(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    if ($sheet['status'] !== 'submitted') {
        setFlash('This tech sheet has already been reviewed and can no longer be edited.', 'error');
        header('Location: tech-sheets.php?action=view&id=' . $id);
        exit;
    }
    if (!pretechSheetEditable($sheet)) {
        setFlash('This sheet\'s photos are under review, so it cannot be edited right now. Once the review is finished (or the photos are sent back) you can edit it again.', 'error');
        header('Location: tech-sheets.php?action=view&id=' . $id);
        exit;
    }

    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    if ($car === null) {
        setFlash('Car not found.', 'error');
        header('Location: garage.php');
        exit;
    }

    if (techSheetIsTaDrift($sheet)) {
        $event = db_get_event($pdo, (int)$sheet['event_id']) ?? ['id' => (int)$sheet['event_id'], 'name' => '', 'event_date' => date('Y-m-d')];
        $event['host_club'] = (string)$sheet['club'];
        renderPageStart('Edit TA/Drift tech sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="tech-sheets.php?action=view&amp;id=' . $id . '">&larr; Back to the sheet</a>']);
        echo renderTaDriftTechSheetFormHtml(taDriftSheetFormVm($car, $event, [], eventsSheetDriverRows($pdo, (int)$user['id'], (int)$car['id']), $sheet,
            db_get_tech_sheet_drivers($pdo, $id), generateCsrfToken()));
        renderPageEnd();
        return;
    }

    if (techSheetIsIce($sheet)) {
        $event = db_get_event($pdo, (int)$sheet['event_id']) ?? ['id' => (int)$sheet['event_id'], 'name' => '', 'event_date' => date('Y-m-d'), 'host_club' => (string)$sheet['club']];
        $event['host_club'] = (string)$sheet['club'];
        renderPageStart('Edit ice tech sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="tech-sheets.php?action=view&amp;id=' . $id . '">&larr; Back to the sheet</a>']);
        echo renderIceTechSheetFormHtml(iceSheetFormVm($car, $event, [], eventsSheetDriverRows($pdo, (int)$user['id'], (int)$car['id']), $sheet, generateCsrfToken()));
        renderPageEnd();
        return;
    }

    $events = db_get_active_events($pdo, DISCIPLINE_SUMMER);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $csrf = generateCsrfToken();
    renderTechSheetEditForm($sheet, $drivers, $events, $csrf, eventsSheetDriverRows($pdo, (int)$user['id'], (int)$car['id']), $car);
}

function renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet, array $existingDrivers, array $ownerDrivers, array $car, int $preselectEventId = 0, array $prefill = []): void {
    $isEdit = $existingSheet !== null;
    if (!$isEdit && isset($prefill['rows'])) $existingDrivers = $prefill['rows'];
    $formAction = $isEdit ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit';
    $pageTitle = $isEdit ? 'Edit tech sheet' : 'Submit tech sheet';
    $entrantName = $isEdit ? $existingSheet['entrant_name'] : $submission['name'];
    $engineHp = $isEdit ? $existingSheet['engine_hp'] : ($submission['dyno_hp'] ?: $submission['declared_hp']);
    $carClass = $isEdit ? $existingSheet['class'] : ($submission['calculated_class'] ?? '');
    $carNeedsColour = trim((string)($car['colour'] ?? '')) === '';
    $carWeight = $isEdit ? (int)$existingSheet['car_weight'] : (int)$submission['competition_weight'];
    $selectedEventId = $isEdit ? (int)$existingSheet['event_id'] : ($preselectEventId ?: null);
    $selectedSheetType = $isEdit ? $existingSheet['sheet_type'] : ($prefill['sheetType'] ?? 'standard');
    $existingChecklist = $isEdit ? (json_decode($existingSheet['checklist_json'] ?? '{}', true) ?: []) : [];
    $existingEquipment = $isEdit ? (json_decode($existingSheet['driver1_equipment_json'] ?? '{}', true) ?: []) : [];
    $existingLogBook = $isEdit ? $existingSheet['log_book_turned_in'] : null;
    $hasEntrantSignature = $isEdit && !empty($existingSheet['entrant_signature_path']);
    $hasDriverSignature = $isEdit && !empty($existingSheet['driver_signature_path']);
    $flash = getFlash();
    $d1 = techSheetDriver1FormState($ownerDrivers, $isEdit ? $existingSheet : null, $prefill['driver1'] ?? null);
    $ownedById = $d1['ownedById'];
    $selfId = $d1['selfId'];
    $driver1Choice = $d1['choice'];
    $driver1NewName = $d1['newName'];
    $driversForJs = $d1['driversForJs'];
    $existingDriversForJs = array_map(function (array $d) use ($ownedById): array {
        $choice = techSheetDriverChoiceFor($ownedById, (string)$d['driver_name']);
        return [
            'driver_number' => (int)$d['driver_number'],
            'driver_choice' => $choice,
            'new_name' => $choice === 'new' ? (string)$d['driver_name'] : '',
            'equipment' => json_decode($d['equipment_json'] ?? '{}', true) ?: [],
        ];
    }, $existingDrivers);
    renderPageStart($pageTitle, 'garage', [
        'flash' => $flash, 'extraHead' => '<meta name="csrf-token" content="' . h($csrf) . '">',
        'subnav' => $isEdit ? '<a href="tech-sheets.php?action=view&amp;id=' . (int)$existingSheet['id'] . '">&larr; Back to the sheet</a>'
                            : '<a href="garage.php?car=' . (int)$car['id'] . '">&larr; Back to the car</a>',
    ]);
    ?>
  <h1 class="hub-page-title"><?= h($pageTitle) ?></h1>
  <?php if (!empty($prefill['notice'])): ?>
  <div class="form-messages show info">More than one driver is ticked for this event. Use the endurance sheet so everyone is on it.
    <a href="<?= h($prefill['notice']) ?>">Use the endurance sheet</a></div>
  <?php endif; ?>

  <form id="tech-sheet-form" method="post" action="<?= h($formAction) ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <?php if ($isEdit): ?>
    <input type="hidden" name="tech_sheet_id" value="<?= (int)$existingSheet['id'] ?>">
    <?php else: ?>
    <input type="hidden" name="car_id" value="<?= (int)($car['id'] ?? 0) ?>">
    <?php endif; ?>
    <input type="hidden" name="checklist_json" id="checklist_json">
    <input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">
    <input type="hidden" name="drivers_json" id="drivers_json">
    <input type="hidden" name="entrant_signature" id="entrant_signature">
    <input type="hidden" name="driver_signature" id="driver_signature">

    <div class="detail-card">
      <h2>Event and sheet type</h2>
      <label for="event_id">Event (required)</label>
      <select id="event_id" name="event_id" required data-message="Choose the event.">
        <?php foreach ($events as $e): ?>
        <option value="<?= (int)$e['id'] ?>" <?= ($e['id'] == $selectedEventId) ? 'selected' : '' ?>><?= h($e['name']) ?> — <?= h(hubEventDate((string)$e['event_date'])) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="sheet_type">Sheet type</label>
      <select id="sheet_type" name="sheet_type">
        <option value="standard" <?= $selectedSheetType === 'standard' ? 'selected' : '' ?>>Standard</option>
        <option value="endurance" <?= $selectedSheetType === 'endurance' ? 'selected' : '' ?>>Endurance (multiple drivers)</option>
      </select>
    </div>

    <div class="detail-card">
      <h2>Car</h2>
      <p class="tech-sheet-car"><span class="hub-plate"><?= h((string)$car['car_number']) ?></span> <?= h(garageCarTitle($car)) ?><?= garageCarSub($car) !== '' ? ' · ' . h(garageCarSub($car)) : '' ?></p>
      <p class="form-hint">Car details come from your Garage and are copied onto the sheet when you submit. <a href="garage.php?car=<?= (int)$car['id'] ?>">Edit car details</a></p>
      <?php if ($carNeedsColour): ?>
      <label for="car_colour">Car colour (required)</label>
      <input type="text" id="car_colour" name="car_colour" maxlength="30" required data-message="Enter the car's colour.">
      <p class="form-hint">Your car has no colour on file yet. It will be saved to the car.</p>
      <?php endif; ?>
    </div>

    <div class="detail-card">
      <h2>Entrant and driver</h2>
      <div class="tech-sheet-header-grid">
        <div><label for="entrant_name">Entrant (required)</label><input type="text" id="entrant_name" name="entrant_name" required data-message="Enter the entrant's name." value="<?= h((string)$entrantName) ?>"></div>
        <div><label for="driver1_choice">Driver name, Driver 1 (required)</label>
          <select id="driver1_choice" name="driver1_choice" required>
            <?php foreach ($ownerDrivers as $d): ?>
            <option value="<?= (int)$d['id'] ?>"<?= (string)(int)$d['id'] === $driver1Choice ? ' selected' : '' ?>><?= h((string)$d['name']) ?><?= (int)$d['id'] === $selfId ? ' (you)' : '' ?></option>
            <?php endforeach; ?>
            <option value="new"<?= $driver1Choice === 'new' ? ' selected' : '' ?>>+ Add a co-driver</option>
          </select>
          <input type="text" id="driver1_new_name" name="driver1_new_name" maxlength="100" placeholder="Co-driver's name" aria-label="Driver 1 name" data-message="Enter the co-driver's name." value="<?= h($driver1NewName) ?>">
        </div>
        <div><label for="engine_hp">Engine HP (optional)</label><input type="text" id="engine_hp" name="engine_hp" value="<?= h((string)$engineHp) ?>"></div>
      </div>
      <p class="form-hint">Driver 1 is the person driving. If you race as a team, put the team name in Entrant.</p>
      <p class="form-hint">Drivers come from your <a href="drivers.php">Drivers</a> page. Choose "+ Add a co-driver" to add someone new: they are added to your Drivers when you submit.</p>
      <input type="hidden" name="class" value="<?= h((string)$carClass) ?>">
      <input type="hidden" name="car_weight" value="<?= (int)$carWeight ?>">
    </div>

    <div class="detail-card">
      <h2>Vehicle checklist</h2>
      <div id="checklist-container"></div>
    </div>

    <div class="detail-card">
      <h2>Driver safety equipment: Driver 1</h2>
      <div id="equipment-container"></div>
    </div>

    <div class="detail-card" id="endurance-drivers-card" <?= $selectedSheetType === 'endurance' ? '' : 'hidden' ?>>
      <h2>Additional drivers</h2>
      <div id="additional-drivers-container"></div>
      <button type="button" class="btn btn-secondary" id="add-driver-btn">+ Add driver</button>
    </div>

    <div class="detail-card">
      <h2>Log book</h2>
      <div class="radio-group" data-radio-group="Log book turned in? (required)" data-message="Choose Yes or No for the log book.">
      <p class="radio-group-label">Log book turned in? (required)</p>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="1" <?= ((string)$existingLogBook === '1') ? 'checked' : '' ?> required> Yes</label>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="0" <?= ($isEdit && (string)$existingLogBook === '0') ? 'checked' : '' ?>> No</label>
      </div>
    </div>

    <div class="detail-card">
      <h2>Declaration and signatures</h2>
      <p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>
      <?php if ($isEdit): ?><p class="form-hint">Leave the pads blank to keep the signatures already on file.</p><?php endif; ?>
      <p id="sig-error" class="field-message" hidden></p>
      <label id="entrant-sig-label">Entrant's signature</label>
      <?php if ($hasEntrantSignature): ?><div><?= techSheetSignatureImg($existingSheet['entrant_signature_path'], 'entrant', techSheetSignatureResolverWeb((int)$existingSheet['id'])) ?></div><?php endif; ?>
      <div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>
      <div id="driver-sig-block">
      <label>Driver's signature</label>
      <?php if ($hasDriverSignature): ?><div><?= techSheetSignatureImg($existingSheet['driver_signature_path'], 'driver', techSheetSignatureResolverWeb((int)$existingSheet['id'])) ?></div><?php endif; ?>
      <div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div>
      </div>
    </div>

    <div id="tech-sheet-error" class="form-messages error" role="alert" hidden></div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn"><?= $isEdit ? 'Save changes' : 'Submit tech sheet' ?></button>
    </div>
  </form>
<script>
  const TECH_CHECKLIST_SECTIONS = <?= json_encode(TECH_CHECKLIST_SECTIONS) ?>;
  const TECH_DRIVER_EQUIPMENT_ITEMS = <?= json_encode(TECH_DRIVER_EQUIPMENT_ITEMS) ?>;
  window.TECH_SHEET_EXISTING_CHECKLIST = <?= json_encode($existingChecklist ?: new stdClass()) ?>;
  window.TECH_SHEET_EXISTING_EQUIPMENT = <?= json_encode($existingEquipment ?: new stdClass()) ?>;
  window.TECH_SHEET_EXISTING_DRIVERS = <?= json_encode($existingDriversForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  window.TECH_SHEET_DRIVERS = <?= json_encode($driversForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = <?= $hasEntrantSignature ? 'true' : 'false' ?>;
  window.TECH_SHEET_HAS_DRIVER_SIGNATURE = <?= $hasDriverSignature ? 'true' : 'false' ?>;
  <?php if (!$isEdit && $selectedEventId): ?>window.TECH_SHEET_DRAFT_KEY = <?= json_encode(techSheetDraftKey((int)($car['owner_user_id'] ?? 0), (int)$car['id'], (int)$selectedEventId)) ?>;<?php endif; ?>
</script>
<script src="js/tech-sheet-checklist.js"></script>
<script src="js/signature-pad.js"></script>
<script src="js/driver-choice.js"></script>
<script src="js/form-problems.js"></script>
<script src="js/tech-sheet-draft.js"></script>
<script src="js/tech-sheet-form.js"></script>
<?php
    renderPageEnd();
}

function renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf, array $ownerDrivers, array $car): void {
    renderTechSheetForm([], $events, $csrf, $sheet, $drivers, $ownerDrivers, $car);
}

function handleTechSheetSignature(PDO $pdo, array $user, int $id, string $which): void {
    $columnMap = [
        'entrant' => 'entrant_signature_path',
        'driver'  => 'driver_signature_path',
        'tech'    => 'tech_signature_path',
    ];
    if (!isset($columnMap[$which])) { http_response_code(404); exit; }

    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) { http_response_code(404); exit; }

    $path = $sheet[$columnMap[$which]] ?? null;
    if (!$path) { http_response_code(404); exit; }

    $fullPath = __DIR__ . '/' . $path;
    if (!file_exists($fullPath)) { http_response_code(404); exit; }

    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

function buildTechSheetMailer(): PHPMailer {
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

/**
 * Decodes and trims the POST fields shared by handleSubmit() and
 * handleUpdate(). Purely a parse step — no validation here (see
 * validateTechSheetPost()).
 */
function parseTechSheetPost(array $post): array {
    return [
        'sheet_type'    => ($post['sheet_type'] ?? 'standard') === 'endurance' ? 'endurance' : 'standard',
        'checklist'     => json_decode($post['checklist_json'] ?? '{}', true) ?: [],
        'equipment'     => json_decode($post['driver1_equipment_json'] ?? '{}', true) ?: [],
        'drivers_input' => json_decode($post['drivers_json'] ?? '[]', true) ?: [],
        'entrant_name'  => trim($post['entrant_name'] ?? ''),
        'driver_name'   => trim($post['driver_name'] ?? ''),
        'car_number'    => trim($post['car_number'] ?? ''),
        'car_colour'    => trim($post['car_colour'] ?? ''),
        'engine_cc'     => trim($post['engine_cc'] ?? '') ?: null,
        'engine_hp'     => trim($post['engine_hp'] ?? '') ?: null,
        'log_book'      => $post['log_book_turned_in'] ?? null,
    ];
}

/**
 * Validates everything handleSubmit()/handleUpdate() share: the checklist,
 * driver-1 equipment, the plain required text fields, and — for endurance
 * sheets — every additional driver's name/number/equipment (this is where
 * the C2 fix lives). Returns the normalized additional-driver rows (possibly
 * an empty array, for a standard sheet or an endurance sheet with none) on
 * success, or null on any failure. Callers must check for null explicitly,
 * not falsiness, since an empty array is a valid result.
 */
function validateTechSheetPost(array $parsed): ?array {
    if (!validateChecklist($parsed['checklist'])) return null;
    if (!validateDriverEquipment($parsed['equipment'])) return null;
    if ($parsed['entrant_name'] === '' || $parsed['driver_name'] === ''
        || $parsed['car_number'] === '' || $parsed['car_colour'] === '') return null;
    if (!in_array($parsed['log_book'], ['0', '1'], true)) return null;

    if ($parsed['sheet_type'] === 'endurance') {
        $driverRows = validateAdditionalDrivers($parsed['drivers_input']);
        if ($driverRows === null) return null;
        return $driverRows;
    }
    return [];
}

/**
 * Sends the tech sheet confirmation email (to the competitor and the club)
 * shared by handleSubmit(), handleUpdate() and handleResendTechSheet().
 * Always embeds signatures as inline data: URIs (see
 * techSheetSignatureResolverEmail()) since recipients have no session.
 */
function sendTechSheetConfirmationEmail(array $sheet, array $drivers, array $event, string $recipientEmail, string $entrantName): bool {
    try {
        $mail = buildTechSheetMailer();
        // Built before rendering: the resolvers below attach embedded images
        // (CIDs) directly to this $mail instance as they resolve each src.
        $bodyHtml = techSheetReceivedEmailHtml(renderTechSheetHtml($sheet, $drivers, $event, techSheetSignatureResolverEmail($mail), emailLogoSrc($mail)));
        $mail->addAddress($recipientEmail, $entrantName);
        $mail->addAddress(TECH_SHEET_EMAIL, TECH_SHEET_EMAIL_NAME);
        $mail->Subject = 'WCMA Tech Sheet — ' . $entrantName . ' — ' . ($event['name'] ?? '');
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->AltBody = COPY_TECH_SHEET_RECEIVED . "\n\n" . 'Your tech sheet for ' . ($event['name'] ?? '') . ' is available online at tech-sheets.php?action=view&id=' . $sheet['id'];
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Tech sheet email error: ' . $e->getMessage());
        return false;
    }
}

function handleSubmit(PDO $pdo, array $user): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    $submission = ($car && $car['archived_at'] === null) ? db_get_car_current_declaration($pdo, $carId) : null;
    if (!$submission) {
        setFlash('Car not found, or it has no class declaration yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1) {
        setFlash('Please choose a valid event.', 'error');
        header('Location: tech-sheets.php?action=new&car_id=' . $carId);
        exit;
    }

    $snap = carsSheetSnapshot($car, $_POST);
    if (!$snap['ok']) {
        setFlash((string)$snap['error'], 'error');
        header('Location: tech-sheets.php?action=new&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : ''));
        exit;
    }

    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    $choices = techSheetApplyDriverChoices($_POST, $owned);
    if (!$choices['ok']) {
        setFlash((string)$choices['error'], 'error');
        header('Location: tech-sheets.php?action=new&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : ''));
        exit;
    }

    $parsed = parseTechSheetPost(array_merge($choices['post'], ['car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? '')]));
    $driverRows = validateTechSheetPost($parsed);
    if ($driverRows === null) {
        setFlash('Please complete every required field, including all driver equipment checklists, before submitting.', 'error');
        $redirect = 'tech-sheets.php?action=new&car_id=' . $carId;
        if ($eventId > 0) $redirect .= '&event_id=' . $eventId;
        header('Location: ' . $redirect);
        // Residual risk (I3): a validation failure here loses the filled-in form,
        // since real re-population from $_POST wasn't built in this fix wave.
        // The C2 client-side validation added alongside this makes hitting this
        // path rare (bad extension/second-tab/race only) — see the fix-wave
        // report for the full rationale.
        exit;
    }

    try {
        $id = db_insert_tech_sheet($pdo, [
            'submission_id' => $submission['id'], 'user_id' => $user['id'], 'event_id' => $eventId, 'sheet_type' => $parsed['sheet_type'],
            'entrant_name' => $parsed['entrant_name'], 'driver_name' => $parsed['driver_name'],
            'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $parsed['car_colour'],
            'car_number' => $parsed['car_number'], 'class' => $submission['calculated_class'] ?? '',
            'engine_cc' => $parsed['engine_cc'], 'engine_hp' => $parsed['engine_hp'],
            'car_weight' => (int)$submission['competition_weight'],
            'checklist_json' => json_encode($parsed['checklist']), 'driver1_equipment_json' => json_encode($parsed['equipment']),
            'log_book_turned_in' => (int)$parsed['log_book'],
        ]);
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: tech-sheets.php?action=new&car_id=' . $carId . ($eventId > 0 ? '&event_id=' . $eventId : ''));
        exit;
    }

    if ($snap['colour_for_car'] !== null) db_update_car($pdo, $carId, ['colour' => $snap['colour_for_car']]);
    eventsTagForSheet($pdo, (int)$user['id'], $event, $car, TECH_TIER_RACE);

    $sigPaths = [];
    if (!empty($_POST['entrant_signature'])) {
        $sigPaths['entrant_signature_path'] = techSheetSaveSignature(__DIR__, $id, 'entrant', $_POST['entrant_signature']);
    }
    if (!empty($_POST['driver_signature'])) {
        $sigPaths['driver_signature_path'] = techSheetSaveSignature(__DIR__, $id, 'driver', $_POST['driver_signature']);
    }
    if (!empty($sigPaths)) {
        db_update_tech_sheet_signatures($pdo, $id, $sigPaths);
    }

    if ($parsed['sheet_type'] === 'endurance' && !empty($driverRows)) {
        db_replace_tech_sheet_drivers($pdo, $id, $driverRows);
    }
    eventsSyncSheetDrivers($pdo, (int)$user['id'], $id);

    $sheet = db_get_tech_sheet($pdo, $id);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $recipientEmail = $user['email'] ?? $submission['email'];
    $sent = sendTechSheetConfirmationEmail($sheet, $drivers, $event, $recipientEmail, $parsed['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);

    setFlash('Tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

function handleUpdate(PDO $pdo, array $user): void {
    $id = (int)($_POST['tech_sheet_id'] ?? 0);
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet || $sheet['status'] !== 'submitted') {
        setFlash('Tech sheet not found or no longer editable.', 'error');
        header('Location: garage.php');
        exit;
    }
    if (!pretechSheetEditable($sheet)) {
        setFlash('This sheet\'s photos are under review, so it cannot be edited right now. Once the review is finished (or the photos are sent back) you can edit it again.', 'error');
        header('Location: tech-sheets.php?action=view&id=' . $id);
        exit;
    }

    if (techSheetIsIce($sheet)) {
        handleUpdateIce($pdo, $user, $sheet);
        return;
    }
    if (techSheetIsTaDrift($sheet)) {
        handleUpdateTaDrift($pdo, $user, $sheet);
        return;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1) {
        setFlash('Please choose a valid event.', 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    $snap = $car !== null ? carsSheetSnapshot($car, $_POST) : ['ok' => false, 'error' => 'Car not found.'];
    if (!$snap['ok']) {
        setFlash((string)$snap['error'], 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    $choices = techSheetApplyDriverChoices($_POST, $owned);
    if (!$choices['ok']) {
        setFlash((string)$choices['error'], 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    $parsed = parseTechSheetPost(array_merge($choices['post'], ['car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? '')]));
    $driverRows = validateTechSheetPost($parsed);
    if ($driverRows === null) {
        setFlash('Please complete every required field, including all driver equipment checklists.', 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        // Residual risk (I3): same as handleSubmit() — see comment there.
        exit;
    }
    $resign = techSheetResignError($sheet, (string)$parsed['entrant_name'], (string)$parsed['driver_name'], $_POST);
    if ($resign !== null) {
        setFlash($resign, 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    try {
        db_update_tech_sheet($pdo, $id, [
            'event_id' => $eventId, 'sheet_type' => $parsed['sheet_type'],
            'entrant_name' => $parsed['entrant_name'], 'driver_name' => $parsed['driver_name'],
            'car_make' => $car['make'], 'car_model' => $car['model'], 'car_colour' => $parsed['car_colour'],
            'car_number' => $parsed['car_number'], 'class' => $sheet['class'],
            'engine_cc' => $parsed['engine_cc'], 'engine_hp' => $parsed['engine_hp'],
            'car_weight' => (int)$sheet['car_weight'],
            'checklist_json' => json_encode($parsed['checklist']), 'driver1_equipment_json' => json_encode($parsed['equipment']),
            'log_book_turned_in' => (int)$parsed['log_book'],
        ]);
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    if ($snap['colour_for_car'] !== null) db_update_car($pdo, (int)$car['id'], ['colour' => $snap['colour_for_car']]);

    if (!empty($_POST['entrant_signature'])) {
        $path = techSheetSaveSignature(__DIR__, $id, 'entrant', $_POST['entrant_signature']);
        if ($path) db_update_tech_sheet_signatures($pdo, $id, ['entrant_signature_path' => $path]);
    }
    if (!empty($_POST['driver_signature'])) {
        $path = techSheetSaveSignature(__DIR__, $id, 'driver', $_POST['driver_signature']);
        if ($path) db_update_tech_sheet_signatures($pdo, $id, ['driver_signature_path' => $path]);
    }

    if ($parsed['sheet_type'] === 'endurance') {
        db_replace_tech_sheet_drivers($pdo, $id, $driverRows);
    } else {
        db_replace_tech_sheet_drivers($pdo, $id, []);
    }
    eventsSyncSheetDrivers($pdo, (int)$user['id'], $id);

    // I6: re-emailing on edit — the club's copy would otherwise go stale after
    // a change unless the competitor separately clicked "Resend Email".
    $updatedSheet = db_get_tech_sheet($pdo, $id);
    $updatedDrivers = db_get_tech_sheet_drivers($pdo, $id);
    $submission = db_get_submission($pdo, (int)$sheet['submission_id']);
    $recipientEmail = $submission['email'] ?? null;
    $sent = $recipientEmail
        ? sendTechSheetConfirmationEmail($updatedSheet, $updatedDrivers, $event, $recipientEmail, $parsed['entrant_name'])
        : false;
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);

    setFlash('Tech sheet updated' . ($sent ? ' and re-emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

function handleNewIce(PDO $pdo, array $user, int $carId, int $eventId): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the ice tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $iceEvents = db_get_active_events($pdo, DISCIPLINE_ICE);
    if (!$iceEvents) {
        setFlash('There are no ice events open for tech sheets yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }
    $event = $iceEvents[0];
    foreach ($iceEvents as $e) {
        if ((int)$e['id'] === $eventId) $event = $e;
    }
    $prefill = eventsSheetPrefill($pdo, (int)$user['id'], $carId, (int)$event['id']);
    renderPageStart('Ice tech sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php?car=' . $carId . '">&larr; Back to the car</a>']);
    echo renderIceTechSheetFormHtml(iceSheetFormVm($car, $event, $iceEvents, eventsSheetDriverRows($pdo, (int)$user['id'], $carId), null, generateCsrfToken(), $prefill['driver1']));
    renderPageEnd();
}

/** Parses (but does not validate) an ice form POST. @return array{ok: bool, error: ?string, parsed: ?array, snap: ?array} */
function iceSheetReadPost(PDO $pdo, array $user, array $car): array {
    $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'parsed' => null, 'snap' => null];
    $snap = carsSheetSnapshot($car, $_POST);
    if (!$snap['ok']) return $fail((string)$snap['error']);
    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    $choices = techSheetApplyDriverChoices(array_merge($_POST, ['sheet_type' => 'standard']), $owned);
    if (!$choices['ok']) return $fail((string)$choices['error']);
    $parsed = iceSheetParsePost(array_merge($choices['post'], [
        'car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? ''),
    ]));
    return ['ok' => true, 'error' => null, 'parsed' => $parsed, 'snap' => $snap];
}

/** Saves the signatures posted with an ice form. */
function iceSheetSaveSignatures(PDO $pdo, int $id): void {
    foreach (['entrant', 'driver'] as $which) {
        if (!empty($_POST[$which . '_signature'])) {
            $path = techSheetSaveSignature(__DIR__, $id, $which, $_POST[$which . '_signature']);
            if ($path) db_update_tech_sheet_signatures($pdo, $id, [$which . '_signature_path' => $path]);
        }
    }
}

function handleSubmitIce(PDO $pdo, array $user): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);
    $back = 'tech-sheets.php?action=new-ice&car_id=' . $carId . '&event_id=' . $eventId;
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the ice tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1 || ($event['discipline'] ?? 'summer') !== DISCIPLINE_ICE) {
        setFlash('Please choose an open ice event.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $read = iceSheetReadPost($pdo, $user, $car);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    $p = $read['parsed'];
    $error = iceSheetValidate($p, (string)$event['host_club']);
    if ($error !== null) {
        setFlash($error, 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $id = db_insert_tech_sheet($pdo, array_merge(iceSheetRow($p, $car), [
            'car_id' => $carId, 'user_id' => $user['id'], 'event_id' => $eventId,
        ]));
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: ' . $back);
        exit;
    }

    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, $carId, ['colour' => $read['snap']['colour_for_car']]);
    eventsTagForSheet($pdo, (int)$user['id'], $event, $car, TECH_TIER_RACE);
    eventsSyncSheetDrivers($pdo, (int)$user['id'], $id);
    iceSheetSaveSignatures($pdo, $id);

    $sheet = db_get_tech_sheet($pdo, $id);
    $recipient = techSheetRecipientEmail($pdo, $sheet);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($sheet, [], $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('Ice tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

/** Update for an ice sheet. The caller has already checked ownership, status and the photo edit lock. */
function handleUpdateIce(PDO $pdo, array $user, array $sheet): void {
    $id = (int)$sheet['id'];
    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    if ($car === null) {
        setFlash('Car not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $read = iceSheetReadPost($pdo, $user, $car);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }
    $p = $read['parsed'];
    $error = iceSheetValidate($p, (string)$sheet['club'])
        ?? techSheetResignError($sheet, (string)$p['entrant_name'], (string)$p['driver_name'], $_POST);
    if ($error !== null) {
        setFlash($error, 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }
    try {
        db_update_tech_sheet($pdo, $id, array_merge(iceSheetRow($p, $car), [
            'event_id' => (int)$sheet['event_id'],
        ]));
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }
    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, (int)$car['id'], ['colour' => $read['snap']['colour_for_car']]);
    eventsSyncSheetDrivers($pdo, (int)$user['id'], $id);
    iceSheetSaveSignatures($pdo, $id);

    $updated = db_get_tech_sheet($pdo, $id);
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $recipient = techSheetRecipientEmail($pdo, $updated);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($updated, [], $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('Ice tech sheet updated' . ($sent ? ' and re-emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

/** The TA/Drift sheet form for one of the user's cars, at an open summer event with a host club (the first one, unless $eventId picks another). */
function handleNewTaDrift(PDO $pdo, array $user, int $carId, int $eventId): void {
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the TA/Drift tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $events = taDriftOpenEvents(db_get_active_events($pdo, DISCIPLINE_SUMMER));
    if (!$events) {
        setFlash('There are no events with a host club open for TA/Drift tech sheets yet.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }
    $event = $events[0];
    foreach ($events as $e) {
        if ((int)$e['id'] === $eventId) $event = $e;
    }
    $pickers = eventsSheetDriverRows($pdo, (int)$user['id'], $carId);
    $byId = [];
    foreach ($pickers as $d) $byId[(int)$d['id']] = $d;
    $prefill = eventsSheetPrefill($pdo, (int)$user['id'], $carId, (int)$event['id']);
    renderPageStart('TA/Drift tech sheet', 'garage', ['flash' => getFlash(), 'subnav' => '<a href="garage.php?car=' . $carId . '">&larr; Back to the car</a>']);
    echo renderTaDriftTechSheetFormHtml(taDriftSheetFormVm($car, $event, $events, $pickers, null, techSheetPrefillRows($byId, $prefill['others']), generateCsrfToken(), $prefill['driver1']));
    renderPageEnd();
}

/** Parses (but does not validate) a TA/Drift form POST. @return array{ok: bool, error: ?string, parsed: ?array, snap: ?array} */
function taDriftSheetReadPost(PDO $pdo, array $user, array $car): array {
    $fail = fn(string $m): array => ['ok' => false, 'error' => $m, 'parsed' => null, 'snap' => null];
    $snap = carsSheetSnapshot($car, $_POST);
    if (!$snap['ok']) return $fail((string)$snap['error']);
    $owned = [];
    foreach (db_get_user_drivers($pdo, (int)$user['id']) as $d) $owned[(int)$d['id']] = $d;
    // 'endurance' makes techSheetApplyDriverChoices() read the added drivers; the sheet itself stays ta_drift.
    $choices = techSheetApplyDriverChoices(array_merge($_POST, ['sheet_type' => 'endurance']), $owned);
    if (!$choices['ok']) return $fail((string)$choices['error']);
    $parsed = taDriftSheetParsePost(array_merge($choices['post'], [
        'car_number' => $snap['car_number'], 'car_colour' => $snap['car_colour'], 'engine_cc' => (string)($snap['engine_cc'] ?? ''),
    ]));
    return ['ok' => true, 'error' => null, 'parsed' => $parsed, 'snap' => $snap];
}

function handleSubmitTaDrift(PDO $pdo, array $user): void {
    $carId = (int)($_POST['car_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);
    $back = 'tech-sheets.php?action=new-ta-drift&car_id=' . $carId . '&event_id=' . $eventId;
    $car = db_get_user_car($pdo, (int)$user['id'], $carId);
    if (!$car || $car['archived_at'] !== null) {
        setFlash('Choose one of your cars for the TA/Drift tech sheet.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1 || taDriftOpenEvents([$event]) === []) {
        setFlash('Please choose an open event with a host club.', 'error');
        header('Location: garage.php?car=' . $carId);
        exit;
    }

    $read = taDriftSheetReadPost($pdo, $user, $car);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    $p = $read['parsed'];
    $valid = taDriftSheetValidate($p, (string)$event['host_club']);
    if ($valid['error'] !== null) {
        setFlash($valid['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        $id = db_insert_tech_sheet($pdo, array_merge(taDriftSheetRow($p, $car), [
            'car_id' => $carId, 'user_id' => $user['id'], 'event_id' => $eventId,
        ]));
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: ' . $back);
        exit;
    }
    db_replace_tech_sheet_drivers($pdo, $id, $valid['drivers']);

    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, $carId, ['colour' => $read['snap']['colour_for_car']]);
    eventsTagForSheet($pdo, (int)$user['id'], $event, $car, TECH_TIER_TA_DRIFT);
    eventsSyncSheetDrivers($pdo, (int)$user['id'], $id);
    iceSheetSaveSignatures($pdo, $id);   // saves the posted entrant/driver signatures (shared with the ice form)

    $sheet = db_get_tech_sheet($pdo, $id);
    $recipient = techSheetRecipientEmail($pdo, $sheet);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($sheet, db_get_tech_sheet_drivers($pdo, $id), $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('TA/Drift tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

/** Update for a TA/Drift sheet. The caller has already checked ownership, status and the photo edit lock. The event stays fixed. */
function handleUpdateTaDrift(PDO $pdo, array $user, array $sheet): void {
    $id = (int)$sheet['id'];
    $back = 'tech-sheets.php?action=edit&id=' . $id;
    $car = db_get_user_car($pdo, (int)$user['id'], (int)$sheet['car_id']);
    if ($car === null) {
        setFlash('Car not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $read = taDriftSheetReadPost($pdo, $user, $car);
    if (!$read['ok']) {
        setFlash((string)$read['error'], 'error');
        header('Location: ' . $back);
        exit;
    }
    $p = $read['parsed'];
    $valid = taDriftSheetValidate($p, (string)$sheet['club']);
    $error = $valid['error'] ?? techSheetResignError($sheet, (string)$p['entrant_name'], (string)$p['driver_name'], $_POST);
    if ($error !== null) {
        setFlash($error, 'error');
        header('Location: ' . $back);
        exit;
    }
    try {
        db_update_tech_sheet($pdo, $id, array_merge(taDriftSheetRow($p, $car), ['event_id' => (int)$sheet['event_id']]));
    } catch (InvalidArgumentException $e) {
        setFlash($e->getMessage(), 'error');
        header('Location: ' . $back);
        exit;
    }
    db_replace_tech_sheet_drivers($pdo, $id, $valid['drivers']);
    eventsSyncSheetDrivers($pdo, (int)$user['id'], $id);
    if ($read['snap']['colour_for_car'] !== null) db_update_car($pdo, (int)$car['id'], ['colour' => $read['snap']['colour_for_car']]);
    iceSheetSaveSignatures($pdo, $id);

    $updated = db_get_tech_sheet($pdo, $id);
    $event = db_get_event($pdo, (int)$sheet['event_id']) ?? [];
    $recipient = techSheetRecipientEmail($pdo, $updated);
    $sent = $recipient !== null && sendTechSheetConfirmationEmail($updated, db_get_tech_sheet_drivers($pdo, $id), $event, $recipient, $p['entrant_name']);
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash('TA/Drift tech sheet updated' . ($sent ? ' and re-emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}

function handleResendTechSheet(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: garage.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);

    // The recipient is the declaration's email, or the account holder's for ice sheets.
    $recipientEmail = techSheetRecipientEmail($pdo, $sheet);

    $sent = $recipientEmail
        ? sendTechSheetConfirmationEmail($sheet, $drivers, $event ?? [], $recipientEmail, $sheet['entrant_name'])
        : false;
    if (!$recipientEmail) {
        error_log('Tech sheet resend error: No email address on file for this tech sheet.');
    }
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);
    setFlash($sent ? 'Tech sheet email re-sent.' : 'Failed to re-send email.', $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}
