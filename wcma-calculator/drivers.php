<?php
// wcma-calculator/drivers.php — the competitor's Drivers page (spec §4): their own profile, the
// co-drivers they manage, licence numbers and this season's gear status. Gear photos stay on gear.php.
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require __DIR__ . '/events-lib.php';
require __DIR__ . '/gear-lib.php';
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

renderPageStart('Drivers', 'drivers', ['flash' => getFlash()]);
echo renderDriversHtml([
    'rows' => driversRows($drivers, $gear, $self !== null ? (int)$self['id'] : 0, $season),
    'season' => $season, 'csrf' => generateCsrfToken(),
    'licenceLink' => seasonLinkMatching(db_get_season_links($pdo, true), 'Licen'),
]);
renderPageEnd();
