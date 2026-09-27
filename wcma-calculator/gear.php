<?php
// wcma-calculator/gear.php — a driver's gear photo pre-tech (the Drivers page links here).
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';
require_once __DIR__ . '/photo-requirements.php';
require_once __DIR__ . '/inspection-lib.php';
require_once __DIR__ . '/pretech-lib.php';
require_once __DIR__ . '/pretech-email.php';
require __DIR__ . '/pretech-page.php';
require_once __DIR__ . '/gear-lib.php';
require __DIR__ . '/gear-email.php';
require __DIR__ . '/gear-page.php';

date_default_timezone_set('America/Denver');

$pdo = db_connect();
db_init($pdo);

// Gear submissions go to the same club address as tech sheets.
define('GEAR_CLUB_EMAIL', db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')));
define('GEAR_CLUB_NAME', db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')));

function requireGearLogin(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: auth.php?action=login');
        exit;
    }
    return $user;
}

/** The signed-in user's own gear record, or a flash + redirect to the list. */
function loadOwnGearRecord(PDO $pdo, array $user, int $id): array {
    $gear = db_get_gear_record($pdo, $id);
    if ($gear === null || (int)$gear['owner_user_id'] !== (int)$user['id']) {
        setFlash('Gear record not found.', 'error');
        header('Location: drivers.php');
        exit;
    }
    return $gear;
}

function requireGearPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: drivers.php'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
    case 'add':
    case 'renew':
        header('Location: drivers.php');
        exit;

    case 'pretech':
        $user = requireGearLogin();
        handleGearPretech($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'pretech-submit':
        $user = requireGearLogin();
        requireGearPost();
        handleGearPretechSubmit($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'start':
        $user = requireGearLogin();
        handleGearStart($pdo, $user, (int)($_GET['driver_id'] ?? 0));
        break;

    case 'start-ice':
        $user = requireGearLogin();
        handleGearStartIce($pdo, $user, (int)($_GET['sheet_id'] ?? 0));
        break;

    default:
        header('Location: drivers.php');
        exit;
}

function handleGearPretech(PDO $pdo, array $user, int $id): void {
    $gear = loadOwnGearRecord($pdo, $user, $id);
    renderGearPretechPage($gear, gearSnapshot($pdo, $id), generateCsrfToken(), getFlash());
}

function handleGearPretechSubmit(PDO $pdo, array $user, int $id): void {
    $gear = loadOwnGearRecord($pdo, $user, $id);

    $result = gearSubmit($pdo, $id);
    if (!$result['ok']) {
        setFlash($result['error'], 'error');
    } else {
        $sent = gearNotify(
            $pdo, 'submitted', db_get_gear_record($pdo, $id),
            feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')),
            ['email' => GEAR_CLUB_EMAIL, 'name' => GEAR_CLUB_NAME], 'emailSmtpSend'
        );
        setFlash('Photos submitted for review.' . ($sent ? ' We emailed you a confirmation.' : ' The confirmation email could not be sent.'), $sent ? 'success' : 'error');
    }
    header('Location: gear.php?action=pretech&id=' . $id);
    exit;
}

/** Opens ice gear photos for the driver on one of the user's ice tech sheets. */
function handleGearStartIce(PDO $pdo, array $user, int $sheetId): void {
    $sheet = db_get_user_tech_sheet($pdo, (int)$user['id'], $sheetId);
    $r = $sheet === null ? ['ok' => false, 'error' => 'Tech sheet not found.'] : gearStartIceForSheet($pdo, $sheet, (int)$user['id']);
    if (!$r['ok']) {
        setFlash((string)$r['error'], 'error');
        header('Location: ' . ($sheet === null ? 'garage.php' : 'tech-sheets.php?action=view&id=' . $sheetId));
        exit;
    }
    header('Location: gear.php?action=pretech&id=' . (int)$r['id']);
    exit;
}

/** Opens this season's gear photos for one of the user's drivers, creating the season's record if needed. */
function handleGearStart(PDO $pdo, array $user, int $driverId): void {
    $driver = db_get_driver($pdo, $driverId);
    if ($driver === null || (int)$driver['owner_user_id'] !== (int)$user['id']) {
        setFlash('Driver not found.', 'error');
        header('Location: drivers.php');
        exit;
    }
    $season = gearSeasonNow();
    $gear = db_get_gear_record_for_driver($pdo, $driverId, $season);
    if ($gear === null) {
        $r = gearCreate($pdo, (int)$user['id'], (string)$driver['name'], '', $season);
        if (!$r['ok']) { setFlash((string)$r['error'], 'error'); header('Location: drivers.php'); exit; }
        $id = (int)$r['id'];
    } else {
        $id = (int)$gear['id'];
    }
    header('Location: gear.php?action=pretech&id=' . $id);
    exit;
}
