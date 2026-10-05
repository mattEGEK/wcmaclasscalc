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
require_once __DIR__ . '/garage-lib.php';   // userRacesSummer()
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

    case 'upgrade-race':
        $user = requireGearLogin();
        requireGearPost();
        handleGearUpgradeRace($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'start':
        $user = requireGearLogin();
        handleGearStart($pdo, $user, (int)($_GET['driver_id'] ?? 0));
        break;

    case 'start-ice':
        $user = requireGearLogin();
        // driver: 1 (or missing) = the sheet's primary driver; 2+ = that added driver on the sheet.
        handleGearStartIce($pdo, $user, (int)($_GET['sheet_id'] ?? 0),
            filter_var($_GET['driver'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
        break;

    case 'start-ta-drift':
        $user = requireGearLogin();
        // driver: 1 (or missing) = the sheet's driver; 2+ = that added driver on the sheet.
        handleGearStartTaDrift($pdo, $user, (int)($_GET['sheet_id'] ?? 0),
            filter_var($_GET['driver'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
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

/** Owner starts race gear photos for gear accepted at TA/Drift. */
function handleGearUpgradeRace(PDO $pdo, array $user, int $id): void {
    loadOwnGearRecord($pdo, $user, $id);
    $r = gearStartRaceUpgrade($pdo, $id);
    setFlash($r['ok'] ? 'Add the race gear photos below. The gear stays teched for TA/Drift meanwhile.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: gear.php?action=pretech&id=' . $id);
    exit;
}

/**
 * Opens ice gear photos for one driver on one of the user's ice tech sheets. $driverNumber is false
 * when the driver query value wasn't a positive integer.
 */
function handleGearStartIce(PDO $pdo, array $user, int $sheetId, int|false $driverNumber): void {
    $sheet = db_get_user_tech_sheet($pdo, (int)$user['id'], $sheetId);
    if ($sheet === null) {
        $r = ['ok' => false, 'error' => 'Tech sheet not found.'];
    } elseif ($driverNumber === false) {
        $r = ['ok' => false, 'error' => 'That driver is not on this sheet.'];
    } else {
        $r = gearStartIceForSheet($pdo, $sheet, (int)$user['id'], $driverNumber);
    }
    if (!$r['ok']) {
        setFlash((string)$r['error'], 'error');
        header('Location: ' . ($sheet === null ? 'garage.php' : 'tech-sheets.php?action=view&id=' . $sheetId));
        exit;
    }
    header('Location: gear.php?action=pretech&id=' . (int)$r['id']);
    exit;
}

/** Opens TA/Drift gear photos for one driver on one of the user's TA/Drift tech sheets. $driverNumber is false for a bad query value. */
function handleGearStartTaDrift(PDO $pdo, array $user, int $sheetId, int|false $driverNumber): void {
    $sheet = db_get_user_tech_sheet($pdo, (int)$user['id'], $sheetId);
    if ($sheet === null) {
        $r = ['ok' => false, 'error' => 'Tech sheet not found.'];
    } elseif ($driverNumber === false) {
        $r = ['ok' => false, 'error' => 'That driver is not on this sheet.'];
    } else {
        $r = gearStartTaDriftForSheet($pdo, $sheet, (int)$user['id'], $driverNumber);
    }
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
    $uid = (int)$user['id'];
    $season = gearSeasonNow();
    $cars = [];
    foreach (db_get_user_cars($pdo, $uid) as $c) $cars[(int)$c['id']] = $c;
    $sheets = db_get_user_tech_sheets($pdo, $uid);
    // TA/Drift only: some summer car, and none of them races (an ice-only user keeps the race default).
    $summerArgs = [$cars, $sheets, db_get_user_current_declarations($pdo, $uid), db_get_user_event_plans($pdo, $uid), db_get_active_events($pdo), date('Y-m-d')];
    $taDriftOnly = userUsesSummer(...$summerArgs) && !userRacesSummer(...$summerArgs);
    // Cage shots when any of this season's TA/Drift sheets is for a caged car; a sheet's own gear link updates it later.
    $caged = false;
    foreach ($sheets as $s) {
        if (techSheetIsTaDrift($s) && (int)$s['season'] === $season && !empty($s['caged'])) $caged = true;
    }
    $r = gearStartForDriver($pdo, $uid, $driver, $season, $taDriftOnly, $caged);
    if (!$r['ok']) { setFlash((string)$r['error'], 'error'); header('Location: drivers.php'); exit; }
    header('Location: gear.php?action=pretech&id=' . (int)$r['id']);
    exit;
}
