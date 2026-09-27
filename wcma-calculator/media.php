<?php
// wcma-calculator/media.php — the Media section (spec 2026-09-27 §4): Announcer, Media kit and Public
// review, for accounts with Media staff access and admins. Review actions are POST-only and CSRF-checked.
require __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('America/Denver');
require __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/feedback-lib.php';          // feedbackBaseUrl()
require __DIR__ . '/tech-status.php';           // techDefaultEventId()
require __DIR__ . '/photo-requirements.php';
require __DIR__ . '/inspection-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-service.php';
require __DIR__ . '/media-page.php';
require __DIR__ . '/pretech-email.php';
require __DIR__ . '/media-email.php';
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/email-helpers.php';

$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
// Re-read the media flag (and role) from the database rather than trusting the session, so revoking
// Media access takes effect on the next request instead of only at the next sign-in.
if ($user !== null) {
    $row = db_find_user_by_id($pdo, (int)$user['id']);
    if ($row === null || (int)($row['active'] ?? 1) === 0) {
        $user = null;
    } else {
        $user['is_media'] = (int)($row['is_media'] ?? 0);
        $user['role'] = $row['role'];
    }
}
if (!mediaCanAccess($user)) {
    http_response_code(403);
    hubRenderForbidden();
    exit;
}

$action = is_string($_GET['action'] ?? null) && $_GET['action'] !== '' ? $_GET['action'] : 'announcer';

if (in_array($action, MEDIA_POST_ACTIONS, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: media.php?action=review'); exit; }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $r = mediaReviewAction($pdo, $action, $driverId, (int)$user['id'], is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
        is_string($_POST['seen'] ?? null) ? $_POST['seen'] : '');
    if (!$r['ok']) {
        setFlash($r['error'], 'error');
    } else {
        $done = ['media-accept' => 'Accepted. The public page is live.', 'media-send-back' => 'Sent back with your note.',
                 'media-hide' => 'Hidden everywhere.', 'media-unhide' => 'Unhidden.'][$action];
        if ($r['notify'] !== null) {
            $sent = mediaNotifyOwner($pdo, $r['notify'], $driverId, $r['note'],
                feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')), 'emailSmtpSend');
            $done .= $sent ? ' The driver was emailed.' : ' The email could not be sent.';
        }
        setFlash($done, 'success');
    }
    header('Location: media.php?action=review');
    exit;
}

$season = (int)date('Y');
$today = date('Y-m-d');
$allEvents = db_get_all_events($pdo);
$pickerEvents = mediaPickerEvents($allEvents, $today);
$pickerIds = array_map(fn(array $e): int => (int)$e['id'], $pickerEvents);
$defaultEventId = techDefaultEventId($allEvents, $today);
$eventParam = is_scalar($_GET['event'] ?? null) ? (int)$_GET['event'] : -1;

switch ($action) {
    case 'kit':
    case 'kit-zip':
        $eventId = in_array($eventParam, $pickerIds, true) ? $eventParam : 0;
        $entries = mediaKitEntries($pdo, $eventId, $season);
        if ($action === 'kit-zip' && class_exists('ZipArchive')) {
            mediaSendZip($pdo, $entries, feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')), $eventId);
        }
        renderPageStart('Media kit', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('kit')]);
        echo renderMediaKitHtml(['events' => $pickerEvents, 'eventId' => $eventId, 'entries' => $entries, 'zip' => class_exists('ZipArchive')]);
        renderPageEnd(['scripts' => '<script src="js/media-kit.js"></script>']);
        break;

    case 'review':
        $queue = mediaReviewQueue($pdo, $season);
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        renderPageStart('Public review', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('review')]);
        echo renderMediaReviewHtml(['queue' => $queue, 'q' => $q, 'found' => $q === '' ? [] : db_search_media_profiles($pdo, $q), 'csrf' => generateCsrfToken()]);
        renderPageEnd();
        break;

    default:
        $eventId = (in_array($eventParam, $pickerIds, true) || $eventParam === $defaultEventId) ? $eventParam : $defaultEventId;
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $extra = $q === '' ? [] : mediaEntriesForDrivers($pdo, array_map(fn(array $r): int => (int)$r['driver_id'], db_search_media_profiles($pdo, $q)), $season);
        renderPageStart('Announcer', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('announcer'), 'bodyClass' => 'media-announcer']);
        echo renderAnnouncerHtml(['events' => $pickerEvents, 'eventId' => $eventId, 'roster' => $eventId > 0 ? mediaAnnouncerRoster($pdo, $eventId) : [],
            'q' => $q, 'extra' => $extra]);
        renderPageEnd();
}

/**
 * Streams a zip of photos ({number}-{name}.{ext}) and profiles.csv, then exits. If the zip can't be
 * built (open()/addFromString()/addFile() fails or throws), nothing is streamed: the caller lands back
 * on the kit page with an error flash instead. The temp file is always removed.
 */
function mediaSendZip(PDO $pdo, array $entries, string $baseUrl, int $eventId): void {
    $tmp = tempnam(sys_get_temp_dir(), 'wcmakit');
    $ok = false;
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
            $csv = fopen('php://temp', 'r+');
            foreach (mediaCsvRows($entries, $baseUrl) as $row) fputcsv($csv, $row);
            rewind($csv);
            $zip->addFromString('profiles.csv', stream_get_contents($csv));
            fclose($csv);
            foreach ($entries as $e) {
                $path = (string)(db_get_media_profile($pdo, (int)$e['driver_id'])['photo_path'] ?? '');
                if ($path === '' || !is_file(__DIR__ . '/' . $path)) continue;
                $name = ($e['number'] !== '' ? mediaSlug($e['number']) . '-' : '') . mediaSlug($e['name']) . '-' . (int)$e['driver_id'] . '.' . pathinfo($path, PATHINFO_EXTENSION);
                $zip->addFile(__DIR__ . '/' . $path, $name);
            }
            $ok = $zip->close() === true;
        }
    } catch (Throwable $e) {
        $ok = false;
    } finally {
        if ($ok) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="wcma-media-kit-' . date('Y-m-d') . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
        }
        if (is_file($tmp)) unlink($tmp);
    }
    if (!$ok) {
        setFlash('Could not build the zip. Try again, or copy the text below.', 'error');
        header('Location: media.php?action=kit&event=' . $eventId);
    }
    exit;
}
