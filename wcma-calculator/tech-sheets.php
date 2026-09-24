<?php
// wcma-calculator/tech-sheets.php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/tech-sheet-data.php';
require __DIR__ . '/tech-sheet-render.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/tech-sheet-files.php';

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
        handleNew($pdo, $user, (int)($_GET['submission_id'] ?? 0));
        break;

    case 'submit':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: tech-sheets.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleSubmit($pdo, $user);
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
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleUpdate($pdo, $user);
        break;

    case 'resend':
        $user = requireTechSheetLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: account.php'); exit; }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
        handleResendTechSheet($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'sig':
        $user = requireTechSheetLogin();
        handleTechSheetSignature($pdo, $user, (int)($_GET['id'] ?? 0), (string)($_GET['which'] ?? ''));
        break;

    default:
        header('Location: account.php');
        exit;
}

function handleNew(PDO $pdo, array $user, int $submissionId): void {
    $submission = db_get_user_submission($pdo, $user['id'], $submissionId);
    if (!$submission) {
        setFlash('Car not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $events = db_get_active_events($pdo);
    if (empty($events)) {
        setFlash('There are no upcoming events open for tech sheet submission yet.', 'error');
        header('Location: account.php');
        exit;
    }

    $csrf = generateCsrfToken();
    renderTechSheetForm($submission, $events, $csrf);
}

function handleView(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $csrf = generateCsrfToken();
    $flash = getFlash();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tech Sheet #<?= (int)$sheet['id'] ?> — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Tech Sheet #' . $sheet['id'], '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>
  <?php if ($flash): ?><div class="form-messages show <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <div class="detail-card actions no-print">
    <?php if ($sheet['status'] === 'submitted'): ?>
    <a href="tech-sheets.php?action=edit&id=<?= (int)$sheet['id'] ?>" class="btn btn-secondary">Edit</a>
    <?php endif; ?>
    <form method="post" action="tech-sheets.php?action=resend" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$sheet['id'] ?>">
      <button type="submit" class="btn btn-primary">Resend Email</button>
    </form>
    <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
  </div>
  <?= renderTechSheetHtml($sheet, $drivers, $event ?? [], techSheetSignatureResolverWeb((int)$sheet['id']), 'assets/wcma-logo.png') ?>
</div>
<script src="js/form-feedback.js"></script>
</body>
</html><?php
}

function handleEdit(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    if ($sheet['status'] !== 'submitted') {
        setFlash('This tech sheet has already been reviewed and can no longer be edited.', 'error');
        header('Location: tech-sheets.php?action=view&id=' . $id);
        exit;
    }

    $events = db_get_active_events($pdo);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $csrf = generateCsrfToken();
    renderTechSheetEditForm($sheet, $drivers, $events, $csrf);
}

function renderTechSheetForm(array $submission, array $events, string $csrf, ?array $existingSheet = null, array $existingDrivers = []): void {
    $isEdit = $existingSheet !== null;
    $formAction = $isEdit ? 'tech-sheets.php?action=update' : 'tech-sheets.php?action=submit';
    $pageTitle = $isEdit ? 'Edit Tech Sheet' : 'Submit Tech Sheet';
    $entrantName = $isEdit ? $existingSheet['entrant_name'] : $submission['name'];
    $driverName = $isEdit ? $existingSheet['driver_name'] : $submission['name'];
    $carNumber = $isEdit ? $existingSheet['car_number'] : '';
    $carColour = $isEdit ? $existingSheet['car_colour'] : '';
    $engineCc = $isEdit ? $existingSheet['engine_cc'] : '';
    $engineHp = $isEdit ? $existingSheet['engine_hp'] : ($submission['dyno_hp'] ?: $submission['declared_hp']);
    $carMake = $isEdit ? $existingSheet['car_make'] : $submission['make'];
    $carModel = $isEdit ? $existingSheet['car_model'] : $submission['model'];
    $carClass = $isEdit ? $existingSheet['class'] : ($submission['calculated_class'] ?? '');
    $carWeight = $isEdit ? (int)$existingSheet['car_weight'] : (int)$submission['competition_weight'];
    $selectedEventId = $isEdit ? (int)$existingSheet['event_id'] : null;
    $selectedSheetType = $isEdit ? $existingSheet['sheet_type'] : 'standard';
    $existingChecklist = $isEdit ? (json_decode($existingSheet['checklist_json'] ?? '{}', true) ?: []) : [];
    $existingEquipment = $isEdit ? (json_decode($existingSheet['driver1_equipment_json'] ?? '{}', true) ?: []) : [];
    $existingLogBook = $isEdit ? $existingSheet['log_book_turned_in'] : null;
    $hasEntrantSignature = $isEdit && !empty($existingSheet['entrant_signature_path']);
    $hasDriverSignature = $isEdit && !empty($existingSheet['driver_signature_path']);
    $existingDriversForJs = array_map(function (array $d): array {
        return [
            'driver_number' => (int)$d['driver_number'],
            'driver_name' => $d['driver_name'],
            'equipment' => json_decode($d['equipment_json'] ?? '{}', true) ?: [],
        ];
    }, $existingDrivers);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader($pageTitle, '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>

  <form id="tech-sheet-form" method="post" action="<?= h($formAction) ?>">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <?php if ($isEdit): ?>
    <input type="hidden" name="tech_sheet_id" value="<?= (int)$existingSheet['id'] ?>">
    <?php else: ?>
    <input type="hidden" name="submission_id" value="<?= (int)$submission['id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="checklist_json" id="checklist_json">
    <input type="hidden" name="driver1_equipment_json" id="driver1_equipment_json">
    <input type="hidden" name="drivers_json" id="drivers_json">
    <input type="hidden" name="entrant_signature" id="entrant_signature">
    <input type="hidden" name="driver_signature" id="driver_signature">

    <div class="detail-card">
      <h2>Event &amp; Sheet Type</h2>
      <label for="event_id">Event</label>
      <select id="event_id" name="event_id" required>
        <?php foreach ($events as $e): ?>
        <option value="<?= (int)$e['id'] ?>" <?= ($e['id'] == $selectedEventId) ? 'selected' : '' ?>><?= h($e['name']) ?> — <?= h(date('M j, Y', strtotime($e['event_date']))) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="sheet_type">Sheet Type</label>
      <select id="sheet_type" name="sheet_type">
        <option value="standard" <?= $selectedSheetType === 'standard' ? 'selected' : '' ?>>Standard</option>
        <option value="endurance" <?= $selectedSheetType === 'endurance' ? 'selected' : '' ?>>Endurance (multiple drivers)</option>
      </select>
    </div>

    <div class="detail-card">
      <h2>Vehicle &amp; Entrant</h2>
      <div class="tech-sheet-header-grid">
        <div><label for="entrant_name">Entrant</label><input type="text" id="entrant_name" name="entrant_name" required value="<?= h((string)$entrantName) ?>"></div>
        <div><label for="driver_name">Driver/Team Name</label><input type="text" id="driver_name" name="driver_name" required value="<?= h((string)$driverName) ?>"></div>
        <div><label for="car_number">Car Number</label><input type="text" id="car_number" name="car_number" required value="<?= h((string)$carNumber) ?>"></div>
        <div><label for="car_colour">Car Colour</label><input type="text" id="car_colour" name="car_colour" required value="<?= h((string)$carColour) ?>"></div>
        <div><label for="engine_cc">Engine CC</label><input type="text" id="engine_cc" name="engine_cc" value="<?= h((string)$engineCc) ?>"></div>
        <div><label for="engine_hp">Engine HP</label><input type="text" id="engine_hp" name="engine_hp" value="<?= h((string)$engineHp) ?>"></div>
      </div>
      <input type="hidden" name="car_make" value="<?= h((string)$carMake) ?>">
      <input type="hidden" name="car_model" value="<?= h((string)$carModel) ?>">
      <input type="hidden" name="class" value="<?= h((string)$carClass) ?>">
      <input type="hidden" name="car_weight" value="<?= (int)$carWeight ?>">
    </div>

    <div class="detail-card">
      <h2>Vehicle Checklist</h2>
      <div id="checklist-container"></div>
    </div>

    <div class="detail-card">
      <h2>Driver Safety Equipment — Driver 1</h2>
      <div id="equipment-container"></div>
    </div>

    <div class="detail-card" id="endurance-drivers-card" <?= $selectedSheetType === 'endurance' ? '' : 'hidden' ?>>
      <h2>Additional Drivers</h2>
      <div id="additional-drivers-container"></div>
      <button type="button" class="btn btn-secondary" id="add-driver-btn">+ Add Driver</button>
    </div>

    <div class="detail-card">
      <h2>Log Book</h2>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="1" <?= ((string)$existingLogBook === '1') ? 'checked' : '' ?> required> Yes</label>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="0" <?= ($isEdit && (string)$existingLogBook === '0') ? 'checked' : '' ?>> No</label>
    </div>

    <div class="detail-card">
      <h2>Declaration &amp; Signatures</h2>
      <p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>
      <?php if ($isEdit): ?><p class="form-hint">Leave the pads blank to keep the signatures already on file.</p><?php endif; ?>
      <label>Entrant's Signature</label>
      <?php if ($hasEntrantSignature): ?><div><?= techSheetSignatureImg($existingSheet['entrant_signature_path'], 'entrant', techSheetSignatureResolverWeb((int)$existingSheet['id'])) ?></div><?php endif; ?>
      <div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>
      <label>Driver's Signature</label>
      <?php if ($hasDriverSignature): ?><div><?= techSheetSignatureImg($existingSheet['driver_signature_path'], 'driver', techSheetSignatureResolverWeb((int)$existingSheet['id'])) ?></div><?php endif; ?>
      <div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn"><?= $isEdit ? 'Save Changes' : 'Submit Tech Sheet' ?></button>
    </div>
    <div id="tech-sheet-error" class="form-messages error" hidden></div>
  </form>
</div>
<script>
  const TECH_CHECKLIST_SECTIONS = <?= json_encode(TECH_CHECKLIST_SECTIONS) ?>;
  const TECH_DRIVER_EQUIPMENT_ITEMS = <?= json_encode(TECH_DRIVER_EQUIPMENT_ITEMS) ?>;
  window.TECH_SHEET_EXISTING_CHECKLIST = <?= json_encode($existingChecklist ?: new stdClass()) ?>;
  window.TECH_SHEET_EXISTING_EQUIPMENT = <?= json_encode($existingEquipment ?: new stdClass()) ?>;
  window.TECH_SHEET_EXISTING_DRIVERS = <?= json_encode($existingDriversForJs) ?>;
  window.TECH_SHEET_HAS_ENTRANT_SIGNATURE = <?= $hasEntrantSignature ? 'true' : 'false' ?>;
  window.TECH_SHEET_HAS_DRIVER_SIGNATURE = <?= $hasDriverSignature ? 'true' : 'false' ?>;
</script>
<script src="js/tech-sheet-checklist.js"></script>
<script src="js/signature-pad.js"></script>
<script src="js/tech-sheet-form.js"></script>
</body>
</html><?php
}

function renderTechSheetEditForm(array $sheet, array $drivers, array $events, string $csrf): void {
    renderTechSheetForm([], $events, $csrf, $sheet, $drivers);
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
        $bodyHtml = '<html><body>' . renderTechSheetHtml($sheet, $drivers, $event, techSheetSignatureResolverEmail($mail), emailLogoSrc($mail)) . '</body></html>';
        $mail->addAddress($recipientEmail, $entrantName);
        $mail->addAddress(TECH_SHEET_EMAIL, TECH_SHEET_EMAIL_NAME);
        $mail->Subject = 'WCMA Tech Sheet — ' . $entrantName . ' — ' . ($event['name'] ?? '');
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->AltBody = 'Your tech sheet for ' . ($event['name'] ?? '') . ' is available online at tech-sheets.php?action=view&id=' . $sheet['id'];
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Tech sheet email error: ' . $e->getMessage());
        return false;
    }
}

function handleSubmit(PDO $pdo, array $user): void {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $submission = db_get_user_submission($pdo, $user['id'], $submissionId);
    if (!$submission) {
        setFlash('Car not found.', 'error');
        header('Location: account.php');
        exit;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1) {
        setFlash('Please choose a valid event.', 'error');
        header('Location: tech-sheets.php?action=new&submission_id=' . $submissionId);
        exit;
    }

    $parsed = parseTechSheetPost($_POST);
    $driverRows = validateTechSheetPost($parsed);
    if ($driverRows === null) {
        setFlash('Please complete every required field, including all driver equipment checklists, before submitting.', 'error');
        header('Location: tech-sheets.php?action=new&submission_id=' . $submissionId);
        // Residual risk (I3): a validation failure here loses the filled-in form,
        // since real re-population from $_POST wasn't built in this fix wave.
        // The C2 client-side validation added alongside this makes hitting this
        // path rare (bad extension/second-tab/race only) — see the fix-wave
        // report for the full rationale.
        exit;
    }

    $id = db_insert_tech_sheet($pdo, [
        'submission_id' => $submission['id'], 'user_id' => $user['id'], 'event_id' => $eventId, 'sheet_type' => $parsed['sheet_type'],
        'entrant_name' => $parsed['entrant_name'], 'driver_name' => $parsed['driver_name'],
        'car_make' => $submission['make'], 'car_model' => $submission['model'], 'car_colour' => $parsed['car_colour'],
        'car_number' => $parsed['car_number'], 'class' => $submission['calculated_class'] ?? '',
        'engine_cc' => $parsed['engine_cc'], 'engine_hp' => $parsed['engine_hp'],
        'car_weight' => (int)$submission['competition_weight'],
        'checklist_json' => json_encode($parsed['checklist']), 'driver1_equipment_json' => json_encode($parsed['equipment']),
        'log_book_turned_in' => (int)$parsed['log_book'],
    ]);

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
        header('Location: account.php');
        exit;
    }

    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = db_get_event($pdo, $eventId);
    if (!$event || (int)$event['active'] !== 1) {
        setFlash('Please choose a valid event.', 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        exit;
    }

    $parsed = parseTechSheetPost($_POST);
    $driverRows = validateTechSheetPost($parsed);
    if ($driverRows === null) {
        setFlash('Please complete every required field, including all driver equipment checklists.', 'error');
        header('Location: tech-sheets.php?action=edit&id=' . $id);
        // Residual risk (I3): same as handleSubmit() — see comment there.
        exit;
    }

    db_update_tech_sheet($pdo, $id, [
        'event_id' => $eventId, 'sheet_type' => $parsed['sheet_type'],
        'entrant_name' => $parsed['entrant_name'], 'driver_name' => $parsed['driver_name'],
        'car_make' => $sheet['car_make'], 'car_model' => $sheet['car_model'], 'car_colour' => $parsed['car_colour'],
        'car_number' => $parsed['car_number'], 'class' => $sheet['class'],
        'engine_cc' => $parsed['engine_cc'], 'engine_hp' => $parsed['engine_hp'],
        'car_weight' => (int)$sheet['car_weight'],
        'checklist_json' => json_encode($parsed['checklist']), 'driver1_equipment_json' => json_encode($parsed['equipment']),
        'log_book_turned_in' => (int)$parsed['log_book'],
    ]);

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

function handleResendTechSheet(PDO $pdo, array $user, int $id): void {
    $sheet = db_get_user_tech_sheet($pdo, $user['id'], $id);
    if (!$sheet) {
        setFlash('Tech sheet not found.', 'error');
        header('Location: account.php');
        exit;
    }
    $event = db_get_event($pdo, (int)$sheet['event_id']);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);

    // current_user() doesn't carry an email; resolve the original submission's email
    // (same fallback handleSubmit() uses when sending the initial confirmation).
    $submission = db_get_submission($pdo, (int)$sheet['submission_id']);
    $recipientEmail = $submission['email'] ?? null;

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
