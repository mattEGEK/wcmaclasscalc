<?php
// wcma-calculator/gear.php — competitor "My Drivers": gear records and gear photo pre-tech.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/pretech-lib.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/pretech-page.php';
require __DIR__ . '/gear-lib.php';
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
        header('Location: gear.php');
        exit;
    }
    return $gear;
}

function requireGearPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: gear.php'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        $user = requireGearLogin();
        handleGearList($pdo, $user);
        break;

    case 'add':
        $user = requireGearLogin();
        requireGearPost();
        handleGearAdd($pdo, $user);
        break;

    case 'renew':
        $user = requireGearLogin();
        requireGearPost();
        handleGearRenew($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    case 'pretech':
        $user = requireGearLogin();
        handleGearPretech($pdo, $user, (int)($_GET['id'] ?? 0));
        break;

    case 'pretech-submit':
        $user = requireGearLogin();
        requireGearPost();
        handleGearPretechSubmit($pdo, $user, (int)($_POST['id'] ?? 0));
        break;

    default:
        header('Location: gear.php');
        exit;
}

function handleGearList(PDO $pdo, array $user): void {
    $prefill = is_string($_GET['name'] ?? null) ? mb_substr(trim($_GET['name']), 0, 100) : null;
    renderGearListPage(db_get_user_gear_records($pdo, (int)$user['id']), gearSeasonNow(), generateCsrfToken(), getFlash(), $prefill);
}

function handleGearAdd(PDO $pdo, array $user): void {
    $r = gearCreate($pdo, (int)$user['id'], (string)($_POST['driver_name'] ?? ''), (string)($_POST['licence_no'] ?? ''), gearSeasonNow());
    setFlash($r['ok'] ? 'Driver added. Open their gear photos to pre-tech their gear, or have it checked at the track.' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: gear.php');
    exit;
}

function handleGearRenew(PDO $pdo, array $user, int $id): void {
    $r = gearRenew($pdo, (int)$user['id'], $id, gearSeasonNow());
    setFlash($r['ok'] ? 'Gear record renewed for ' . gearSeasonNow() . '.' : $r['error'], $r['ok'] ? 'success' : 'error');
    header('Location: gear.php');
    exit;
}

function handleGearPretech(PDO $pdo, array $user, int $id): void {
    $gear = loadOwnGearRecord($pdo, $user, $id);
    renderGearPretechPage($gear, gearSnapshot($pdo, $id), generateCsrfToken(), getFlash());
}

function handleGearPretechSubmit(PDO $pdo, array $user, int $id): void {
    loadOwnGearRecord($pdo, $user, $id);

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
