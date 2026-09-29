<?php
// wcma-calculator/index.php — the hub front door: landing page when signed out, Home when signed in.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require __DIR__ . '/config.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';
require_once __DIR__ . '/gear-lib.php';
require_once __DIR__ . '/events-lib.php';
require_once __DIR__ . '/clubs-lib.php';
require_once __DIR__ . '/reminders-lib.php';
require_once __DIR__ . '/readiness-lib.php';
require_once __DIR__ . '/garage-lib.php';
require __DIR__ . '/media-lib.php';
require __DIR__ . '/home-page.php';

date_default_timezone_set('America/Denver');
$pdo = db_connect();
db_init($pdo);
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_role('user');
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { http_response_code(403); die('Invalid CSRF token'); }
    $uid = (int)$user['id'];
    switch ($_POST['action'] ?? '') {
        case 'tag':
            $r = eventsTagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
            $extra = $r['ok'] ? remindersRecordTagChoice($pdo, $uid, $_POST) : '';
            setFlash($r['ok'] ? 'Added to your events. ' . EVENTS_NOT_REGISTERING . $extra : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'untag':
            $r = eventsUntagCar($pdo, $uid, (int)($_POST['event_id'] ?? 0), (int)($_POST['car_id'] ?? 0));
            setFlash($r['ok'] ? 'Removed from your events.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'at-track':
            $r = eventsSetAtTrack($pdo, $uid, (string)($_POST['subject_type'] ?? ''), (int)($_POST['subject_id'] ?? 0), (int)($_POST['season'] ?? 0),
                (string)($_POST['discipline'] ?? 'summer'), (string)($_POST['club'] ?? ''));
            setFlash($r['ok'] ? 'Noted: you\'ll get it checked at the track.' : (string)$r['error'], $r['ok'] ? 'success' : 'error');
            break;
        case 'media-prompt-dismiss':
            db_dismiss_media_prompt($pdo, $uid);
            setFlash('OK. You can add one any time from the Drivers page.', 'success');
            break;
    }
    header('Location: index.php');
    exit;
}

if ($user === null) {
    renderPageStart('Welcome', 'home');
    echo renderLandingHtml(db_get_season_links($pdo, true), landingNextIsIce(db_get_active_events($pdo), date('Y-m-d')));
    renderPageEnd();
    exit;
}

$uid = (int)$user['id'];
$in = loadReadinessInputs($pdo, $uid, date('Y-m-d'));
$season = gearSeasonNow();
$iceSeason = gearSeasonNow(DISCIPLINE_ICE);
$today = (string)$in['today'];

// Which upcoming active event(s) each car is tagged to, keyed by discipline, for usesSummer/ice.
$eventsById = [];
foreach ($in['events'] as $e) $eventsById[(int)$e['id']] = $e;
$taggedByCar = [];
foreach ($in['plans'] as $p) $taggedByCar[(int)$p['car_id']][] = (int)$p['event_id'];

$hasIceActivity = userHasIceActivity($in['sheets'], (bool)$in['iceGear'], $in['plans'], $in['events'], $in['cars']);
$userUsesSummer = userUsesSummer($in['cars'], $in['sheets'], $in['declarations'], $in['plans'], $in['events'], $today);

$garage = [];
foreach ($in['cars'] as $carId => $car) {
    $status = techCarStatus(db_get_identity_sheets($pdo, $carId, $season));
    $carSheets = array_values(array_filter($in['sheets'], fn(array $s): bool => (int)$s['car_id'] === $carId));
    $decl = $in['declarations'][$carId] ?? null;
    $taggedIce = $taggedSummer = false;
    foreach ($taggedByCar[$carId] ?? [] as $eid) {
        $e = $eventsById[$eid] ?? null;
        if ($e === null || (string)$e['event_date'] < $today) continue;
        if (($e['discipline'] ?? 'summer') === 'ice') $taggedIce = true; else $taggedSummer = true;
    }
    $stored = isset($car['disciplines']) ? (string)$car['disciplines'] : null;
    $garage[] = ['car' => $car, 'declaration' => $decl,
                 'techLabel' => techCarStatusLabel($status, $season), 'techState' => $status['state'],
                 'usesSummer' => garageCarUsesSummer($decl !== null ? [$decl] : [], $carSheets, $taggedSummer, $taggedIce, $stored),
                 'ice' => garageIceSummary($carSheets, $taggedIce, $iceSeason, $stored)];
}
$drivers = [];
foreach ($in['drivers'] as $did => $d) {
    $g = $in['gear']["$did:$season"] ?? null;
    $st = $g !== null ? gearStatus($g) : ['state' => 'none', 'via' => null];
    $ice = null;
    if ($hasIceActivity) {
        // loadReadinessInputs() already loaded the prior-season summer record whenever there's an
        // active ice event (for carry-over); only query when that key genuinely isn't there.
        $summerKey = "$did:" . ($iceSeason - 1);
        $summerPrev = array_key_exists($summerKey, $in['gear']) ? $in['gear'][$summerKey] : db_get_gear_record_for_driver($pdo, $did, $iceSeason - 1);
        // iceGear only holds seasons of active ice events: fall back to the DB like $summerPrev.
        $iceKey = "$did:$iceSeason";
        $iceCur = array_key_exists($iceKey, $in['iceGear']) ? $in['iceGear'][$iceKey] : db_get_gear_record_for_driver($pdo, $did, $iceSeason, DISCIPLINE_ICE);
        $ice = gearIceSummary($iceCur, $summerPrev, $iceSeason);
    }
    $drivers[] = ['name' => (string)$d['name'], 'isSelf' => $did === $in['selfDriverId'],
                  'gearLabel' => gearStatusLabel($st, $season), 'gearState' => $st['state'],
                  'showSummer' => driverShowsSummerGear($hasIceActivity, $userUsesSummer, $g !== null),
                  'ice' => $ice];
}

$userRow = db_find_user_by_id($pdo, $uid);
$selfDriver = db_get_self_driver($pdo, $uid);
// Only offer the prompt when the driver has never made a media consent choice at all: once they
// withdraw, that withdrawal is a deliberate choice (a consent row with media off), not "not set up".
$mediaInvite = $selfDriver !== null && (int)($userRow['media_prompt_dismissed'] ?? 0) === 0
    && db_get_latest_media_consent($pdo, (int)$selfDriver['id']) === null;
// Sent back / hidden profiles for any driver this account manages take over the same card.
$managedDrivers = db_get_user_drivers($pdo, $uid);
$mediaPrompt = mediaHomePrompt($mediaInvite, $managedDrivers,
    db_get_media_bundle($pdo, array_map(fn(array $d): int => (int)$d['id'], $managedDrivers)));

renderPageStart('Home', 'home', ['flash' => getFlash()]);
echo renderHomeHtml([
    'name' => (string)$user['name'], 'readiness' => buildReadiness($in), 'cars' => $in['cars'],
    'garage' => $garage, 'drivers' => $drivers, 'seasonLinks' => db_get_season_links($pdo, true),
    'csrf' => generateCsrfToken(), 'offerReminders' => remindersShouldOffer($userRow),
    'mediaPrompt' => $mediaPrompt,
    'focusEventId' => is_string($_GET['event'] ?? null) && ctype_digit($_GET['event']) ? (int)$_GET['event'] : null,
]);
renderPageEnd();
