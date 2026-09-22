<?php
// wcma-calculator/tech-sheets.php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/tech-sheet-data.php';
require __DIR__ . '/tech-sheet-render.php';

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('America/Denver');

const TECH_SHEET_EMAIL = 'classing@wcma.ca';
const TECH_SHEET_EMAIL_NAME = 'WCMA Classing';

$pdo = db_connect();
db_init($pdo);

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

function renderTechSheetForm(array $submission, array $events, string $csrf): void {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Submit Tech Sheet — WCMA Calculator</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="stylesheet" href="css/calculator.css">
<meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body>
<div class="container">
  <?php renderSiteHeader('Submit Tech Sheet', '<a href="account.php">← Back to My Cars</a>' . renderCommonNav('account')); ?>

  <form id="tech-sheet-form" method="post" action="tech-sheets.php?action=submit">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="submission_id" value="<?= (int)$submission['id'] ?>">
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
        <option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?> — <?= h(date('M j, Y', strtotime($e['event_date']))) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="sheet_type">Sheet Type</label>
      <select id="sheet_type" name="sheet_type">
        <option value="standard">Standard</option>
        <option value="endurance">Endurance (multiple drivers)</option>
      </select>
    </div>

    <div class="detail-card">
      <h2>Vehicle &amp; Entrant</h2>
      <div class="tech-sheet-header-grid">
        <div><label for="entrant_name">Entrant</label><input type="text" id="entrant_name" name="entrant_name" required value="<?= h($submission['name']) ?>"></div>
        <div><label for="driver_name">Driver/Team Name</label><input type="text" id="driver_name" name="driver_name" required value="<?= h($submission['name']) ?>"></div>
        <div><label for="car_number">Car Number</label><input type="text" id="car_number" name="car_number" required></div>
        <div><label for="car_colour">Car Colour</label><input type="text" id="car_colour" name="car_colour" required></div>
        <div><label for="engine_cc">Engine CC</label><input type="text" id="engine_cc" name="engine_cc"></div>
        <div><label for="engine_hp">Engine HP</label><input type="text" id="engine_hp" name="engine_hp" value="<?= h((string)($submission['dyno_hp'] ?: $submission['declared_hp'])) ?>"></div>
      </div>
      <input type="hidden" name="car_make" value="<?= h($submission['make']) ?>">
      <input type="hidden" name="car_model" value="<?= h($submission['model']) ?>">
      <input type="hidden" name="class" value="<?= h($submission['calculated_class'] ?? '') ?>">
      <input type="hidden" name="car_weight" value="<?= (int)$submission['competition_weight'] ?>">
    </div>

    <div class="detail-card">
      <h2>Vehicle Checklist</h2>
      <div id="checklist-container"></div>
    </div>

    <div class="detail-card">
      <h2>Driver Safety Equipment — Driver 1</h2>
      <div id="equipment-container"></div>
    </div>

    <div class="detail-card" id="endurance-drivers-card" hidden>
      <h2>Additional Drivers</h2>
      <div id="additional-drivers-container"></div>
      <button type="button" class="btn btn-secondary" id="add-driver-btn">+ Add Driver</button>
    </div>

    <div class="detail-card">
      <h2>Log Book</h2>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="1" required> Yes</label>
      <label class="checkbox-label"><input type="radio" name="log_book_turned_in" value="0"> No</label>
    </div>

    <div class="detail-card">
      <h2>Declaration &amp; Signatures</h2>
      <p><em>I hereby stipulate that the above vehicle meets the regulations for the event.</em></p>
      <label>Entrant's Signature</label>
      <div class="sig-pad-wrap"><canvas id="entrant-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="entrant">Clear</button></div>
      <label>Driver's Signature</label>
      <div class="sig-pad-wrap"><canvas id="driver-sig-canvas"></canvas></div>
      <div class="sig-pad-actions"><button type="button" class="link-button" data-clear-sig="driver">Clear</button></div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary" id="tech-sheet-submit-btn">Submit Tech Sheet</button>
    </div>
    <div id="tech-sheet-error" class="form-messages error" hidden></div>
  </form>
</div>
<script>
  const TECH_CHECKLIST_SECTIONS = <?= json_encode(TECH_CHECKLIST_SECTIONS) ?>;
  const TECH_DRIVER_EQUIPMENT_ITEMS = <?= json_encode(TECH_DRIVER_EQUIPMENT_ITEMS) ?>;
</script>
<script src="js/tech-sheet-checklist.js"></script>
<script src="js/signature-pad.js"></script>
<script src="js/tech-sheet-form.js"></script>
</body>
</html><?php
}

function saveSignatureFile(int $techSheetId, string $field, string $dataUrl): ?string {
    if (strpos($dataUrl, 'data:image/png;base64,') !== 0) return null;
    $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')));
    if ($binary === false) return null;
    if (substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

    $dir = __DIR__ . '/uploads/tech-sheets/' . $techSheetId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $relative = 'uploads/tech-sheets/' . $techSheetId . '/' . $field . '.png';
    file_put_contents(__DIR__ . '/' . $relative, $binary);
    return $relative;
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

    $sheetType = ($_POST['sheet_type'] ?? 'standard') === 'endurance' ? 'endurance' : 'standard';
    $checklist = json_decode($_POST['checklist_json'] ?? '{}', true) ?: [];
    $equipment = json_decode($_POST['driver1_equipment_json'] ?? '{}', true) ?: [];
    $driversInput = json_decode($_POST['drivers_json'] ?? '[]', true) ?: [];
    $entrantName = trim($_POST['entrant_name'] ?? '');
    $driverName = trim($_POST['driver_name'] ?? '');
    $carNumber = trim($_POST['car_number'] ?? '');
    $carColour = trim($_POST['car_colour'] ?? '');
    $logBook = $_POST['log_book_turned_in'] ?? null;

    if (!validateChecklist($checklist) || !validateDriverEquipment($equipment)
        || $entrantName === '' || $driverName === '' || $carNumber === '' || $carColour === ''
        || !in_array($logBook, ['0', '1'], true)) {
        setFlash('Please complete every required field before submitting.', 'error');
        header('Location: tech-sheets.php?action=new&submission_id=' . $submissionId);
        exit;
    }

    $id = db_insert_tech_sheet($pdo, [
        'submission_id' => $submission['id'], 'user_id' => $user['id'], 'event_id' => $eventId, 'sheet_type' => $sheetType,
        'entrant_name' => $entrantName, 'driver_name' => $driverName,
        'car_make' => $submission['make'], 'car_model' => $submission['model'], 'car_colour' => $carColour,
        'car_number' => $carNumber, 'class' => $submission['calculated_class'] ?? '',
        'engine_cc' => trim($_POST['engine_cc'] ?? '') ?: null, 'engine_hp' => trim($_POST['engine_hp'] ?? '') ?: null,
        'car_weight' => (int)$submission['competition_weight'],
        'checklist_json' => json_encode($checklist), 'driver1_equipment_json' => json_encode($equipment),
        'log_book_turned_in' => (int)$logBook,
    ]);

    $sigPaths = [];
    if (!empty($_POST['entrant_signature'])) {
        $sigPaths['entrant_signature_path'] = saveSignatureFile($id, 'entrant', $_POST['entrant_signature']);
    }
    if (!empty($_POST['driver_signature'])) {
        $sigPaths['driver_signature_path'] = saveSignatureFile($id, 'driver', $_POST['driver_signature']);
    }
    if (!empty($sigPaths)) {
        db_update_tech_sheet_signatures($pdo, $id, $sigPaths);
    }

    if ($sheetType === 'endurance' && !empty($driversInput)) {
        $driverRows = [];
        foreach ($driversInput as $d) {
            $driverRows[] = [
                'driver_number' => (int)($d['driver_number'] ?? 0),
                'driver_name' => trim($d['driver_name'] ?? ''),
                'equipment_json' => json_encode($d['equipment'] ?? []),
            ];
        }
        db_replace_tech_sheet_drivers($pdo, $id, $driverRows);
    }

    $sheet = db_get_tech_sheet($pdo, $id);
    $drivers = db_get_tech_sheet_drivers($pdo, $id);
    $bodyHtml = '<html><body>' . renderTechSheetHtml($sheet, $drivers, $event) . '</body></html>';
    $subject = 'WCMA Tech Sheet — ' . $entrantName . ' — ' . $event['name'];

    $sent = false;
    try {
        $mail = buildTechSheetMailer();
        $mail->addAddress($user['email'] ?? $submission['email'], $entrantName);
        $mail->addAddress(TECH_SHEET_EMAIL, TECH_SHEET_EMAIL_NAME);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $bodyHtml;
        $mail->AltBody = 'Your tech sheet for ' . $event['name'] . ' has been submitted. View it online at tech-sheets.php?action=view&id=' . $id;
        $mail->send();
        $sent = true;
    } catch (Exception $e) {
        error_log('Tech sheet submit email error: ' . $e->getMessage());
    }
    db_update_email_sent_tech_sheet($pdo, $id, $sent ? 1 : 0);

    setFlash('Tech sheet submitted' . ($sent ? ' and emailed to you and the club.' : ', but the confirmation email failed to send.'), $sent ? 'success' : 'error');
    header('Location: tech-sheets.php?action=view&id=' . $id);
    exit;
}
