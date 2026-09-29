<?php
// wcma-calculator/drivers.php — the competitor's Drivers page (spec §4): their own profile, the
// co-drivers they manage, licence numbers and this season's gear status. Gear photos stay on gear.php.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require_once __DIR__ . '/events-lib.php';
require_once __DIR__ . '/gear-lib.php';
require_once __DIR__ . '/ice-sheet-lib.php';
require_once __DIR__ . '/garage-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/drivers-lib.php';
require __DIR__ . '/home-page.php';
require __DIR__ . '/drivers-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = require_role('user');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    switch ($_POST['action'] ?? '') {
        case 'add':
            $r = driversAdd($pdo, $uid, (string)($_POST['name'] ?? ''), (string)($_POST['licence_no'] ?? ''));
            setFlash($r['ok'] ? 'Co-driver added. Add photos of their gear, or have it checked at the track.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'licence':
            $r = driversSetLicence($pdo, $uid, (int)($_POST['driver_id'] ?? 0), (string)($_POST['licence_no'] ?? ''));
            setFlash($r['ok'] ? 'Licence number saved.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
    }
    header('Location: drivers.php');
    exit;
}

$season = gearSeasonNow();
$drivers = db_get_user_drivers($pdo, $uid);
$gear = [];
foreach ($drivers as $d) {
    $g = db_get_gear_record_for_driver($pdo, (int)$d['id'], $season);
    if ($g !== null) $gear[(int)$d['id']] = $g;
}
$self = db_get_self_driver($pdo, $uid);

$iceSeason = gearSeasonNow(DISCIPLINE_ICE);
$userSheets = db_get_user_tech_sheets($pdo, $uid);
$iceSheets = array_values(array_filter($userSheets, fn(array $s): bool => techSheetIsIce($s)));

// The sheet's own driver_id AND any added drivers (tech_sheet_drivers) both count, per
// readiness-lib.php's $sheetDrivers pattern — an added driver still needs a photos link.
$sheetDrivers = db_get_drivers_for_sheets($pdo, array_map(fn(array $s): int => (int)$s['id'], $iceSheets));
$iceSheetByDriver = driversIceSheetIdsByDriver($iceSheets, $sheetDrivers, $iceSeason);

// One query for every gear record this owner has, instead of two DB round-trips per driver: also
// lets us tell whether the user has any ice activity at all before building $ice.
$ownerGear = db_get_user_gear_records($pdo, $uid);
$gearByKey = [];
$hasIceGear = false;
foreach ($ownerGear as $g) {
    $discipline = (string)($g['discipline'] ?? DISCIPLINE_SUMMER);
    $gearByKey[(int)$g['driver_id'] . ':' . (int)$g['season'] . ':' . $discipline] = $g;
    if ($discipline === DISCIPLINE_ICE) $hasIceGear = true;
}
// Same rule as Home: an ice sheet, ice gear, or a car tagged to an active ice event.
$plans = db_get_user_event_plans($pdo, $uid);
$activeEvents = db_get_active_events($pdo);
$cars = [];
foreach (db_get_user_cars($pdo, $uid) as $c) $cars[(int)$c['id']] = $c;
$iceActivity = userHasIceActivity($userSheets, $hasIceGear, $plans, $activeEvents, $cars);
$userUsesSummer = userUsesSummer($cars, $userSheets, db_get_user_current_declarations($pdo, $uid), $plans, $activeEvents, date('Y-m-d'));

$ice = [];
if ($iceActivity) {
    foreach ($drivers as $d) {
        $did = (int)$d['id'];
        $iceGear = $gearByKey["$did:$iceSeason:" . DISCIPLINE_ICE] ?? null;
        $summerPrev = $gearByKey["$did:" . ($iceSeason - 1) . ':' . DISCIPLINE_SUMMER] ?? null;
        $iceNote = $iceGear !== null && ($iceGear['status'] ?? '') !== 'accepted' ? trim((string)($iceGear['revoke_note'] ?? '')) : '';
        $ice[$did] = gearIceSummary($iceGear, $summerPrev, $iceSeason) + ($iceSheetByDriver[$did] ?? ['sheetId' => null, 'driverNumber' => 1])
            + ['revokeNote' => $iceNote !== '' ? $iceNote : null];
    }
}

renderPageStart('Drivers', 'drivers', ['flash' => getFlash()]);
echo renderDriversHtml([
    'rows' => driversRows($drivers, $gear, $self !== null ? (int)$self['id'] : 0, $season,
        db_get_media_bundle($pdo, array_map(fn(array $d): int => (int)$d['id'], $drivers)), $ice, $userUsesSummer),
    'season' => $season, 'csrf' => generateCsrfToken(),
    'licenceLink' => seasonLinkMatching(db_get_season_links($pdo, true), 'Licen'),
]);
renderPageEnd();
