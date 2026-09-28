<?php
// wcma-calculator/driver.php — a driver's public page (spec 2026-09-27 §5). Shown only while public
// consent is on, Media staff accepted it and it isn't hidden; otherwise the same 404 whatever the reason.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view_helpers.php';
require_once __DIR__ . '/ice-sheet-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/media-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$driverId = (int)($_GET['id'] ?? 0);
$driver = db_get_driver($pdo, $driverId);
$profile = $driver !== null ? db_get_media_profile($pdo, $driverId) : null;
$consent = $driver !== null ? db_get_latest_media_consent($pdo, $driverId) : null;

if ($driver === null || !mediaUsable($profile, $consent, 'public')) {
    http_response_code(404);
    renderPageStart('Not available', '');
    echo '<h1>This profile isn&#039;t available</h1><p><a href="index.php">Go to the WCMA Hub</a></p>';
    renderPageEnd();
    exit;
}
$sheet = db_get_driver_latest_sheet($pdo, $driverId);
$entry = mediaEntry($driver, $profile, db_get_sponsors($pdo, $driverId), (string)($sheet['car_number'] ?? ''),
    $sheet !== null ? mediaCarLabel($sheet) : '', $sheet !== null ? techSheetClassLine($sheet) : '', true);
renderPageStart((string)$driver['name'], '', ['extraHead' => '<meta name="description" content="' . h(mb_substr(trim($entry['blurb']), 0, 155)) . '">']);
echo renderPublicDriverHtml($entry);
renderPageEnd();
