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

$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
if (!mediaCanAccess($user)) {
    http_response_code(403);
    hubRenderForbidden();
    exit;
}

$action = is_string($_GET['action'] ?? null) && $_GET['action'] !== '' ? $_GET['action'] : 'announcer';
$season = (int)date('Y');
$events = db_get_all_events($pdo);
$eventIds = array_map(fn(array $e): int => (int)$e['id'], $events);
$eventParam = is_scalar($_GET['event'] ?? null) ? (int)$_GET['event'] : -1;

switch ($action) {
    case 'kit':
    case 'kit-zip':
        $eventId = in_array($eventParam, $eventIds, true) ? $eventParam : 0;
        $entries = mediaKitEntries($pdo, $eventId, $season);
        if ($action === 'kit-zip' && class_exists('ZipArchive')) {
            mediaSendZip($pdo, $entries, feedbackBaseUrl($_SERVER, (string)config_default('SITE_BASE_URL', '')), $eventId);
        }
        renderPageStart('Media kit', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('kit')]);
        echo renderMediaKitHtml(['events' => $events, 'eventId' => $eventId, 'entries' => $entries, 'zip' => class_exists('ZipArchive')]);
        renderPageEnd(['scripts' => '<script src="js/media-kit.js"></script>']);
        break;

    default:
        $eventId = in_array($eventParam, $eventIds, true) ? $eventParam : techDefaultEventId($events, date('Y-m-d'));
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
        $extra = $q === '' ? [] : mediaEntriesForDrivers($pdo, array_map(fn(array $r): int => (int)$r['driver_id'], db_search_media_profiles($pdo, $q)), $season);
        renderPageStart('Announcer', 'media', ['flash' => getFlash(), 'subnav' => mediaSubnavHtml('announcer'), 'bodyClass' => 'media-announcer']);
        echo renderAnnouncerHtml(['events' => $events, 'eventId' => $eventId, 'roster' => $eventId > 0 ? mediaAnnouncerRoster($pdo, $eventId) : [],
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
            $zip->close();
            $ok = true;
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
